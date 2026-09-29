<?php
declare(strict_types=1);

namespace Controllers\Admin;

use DB, View, Auth, Attrs, Catalog;

/** Словник характеристик: назви, значення, привʼязка до категорій */
class Attributes
{
    public static function index(): never
    {
        Auth::requireCap('catalog.manage');
        if (is_post()) self::handle();

        $attrs = Attrs::all(false);
        $usage = [];
        foreach ($attrs as $a) $usage[(int)$a['id']] = Attrs::usage((int)$a['id']);
        // скільки товарів має конкретне значення — щоб попереджати перед видаленням
        $valUsage = [];
        foreach (DB::all('SELECT value_id, COUNT(DISTINCT product_id) AS cnt FROM product_attrs WHERE value_id IS NOT NULL GROUP BY value_id') as $r) {
            $valUsage[(int)$r['value_id']] = (int)$r['cnt'];
        }
        foreach (DB::all('SELECT value_id, COUNT(*) AS cnt FROM variant_options WHERE value_id IS NOT NULL GROUP BY value_id') as $r) {
            $valUsage[(int)$r['value_id']] = ($valUsage[(int)$r['value_id']] ?? 0) + (int)$r['cnt'];
        }

        View::show('admin/attributes', [
            'attrs' => $attrs, 'usage' => $usage, 'val_usage' => $valUsage,
            'categories' => Catalog::categories(),
            'open' => (int)($_GET['open'] ?? 0),
            'page_title' => 'Характеристики — адмінка',
        ], 'layouts/admin');
    }

    private static function handle(): never
    {
        $action = $_POST['_action'] ?? '';
        $id = (int)($_POST['id'] ?? 0);

        if ($action === 'create') {
            $name = trim($_POST['name'] ?? '');
            if ($name === '') { flash('error', 'Вкажіть назву'); redirect('/admin/attributes'); }
            if (DB::row('SELECT id FROM attributes WHERE slug = ?', [slugify($name)])) {
                flash('error', 'Характеристика «' . $name . '» вже існує');
                redirect('/admin/attributes');
            }
            $a = Attrs::ensure($name, $_POST['type'] ?? 'select');
            $aid = (int)$a['id'];
            DB::update('attributes', [
                'unit' => trim($_POST['unit'] ?? '') ?: null,
                'filterable' => isset($_POST['filterable']) ? 1 : 0,
            ], 'id = ?', [$aid]);
            Attrs::setCategories($aid, (array)($_POST['categories'] ?? []));
            foreach (self::parseValues($_POST['values'] ?? '') as $v) Attrs::ensureValue($aid, $v);
            flash('success', 'Характеристику «' . $name . '» створено');
            redirect('/admin/attributes?open=' . $aid);
        }

        if ($action === 'update' && $id) {
            // ✕ біля значення — видалення разом зі збереженням решти
            $delValue = (int)($_POST['delete_value_id'] ?? 0);
            if ($delValue) {
                $v = DB::row('SELECT * FROM attribute_values WHERE id = ? AND attribute_id = ?', [$delValue, $id]);
                if ($v) { Attrs::deleteValue($delValue); flash('success', 'Значення «' . $v['value'] . '» видалено'); }
            }
            $name = trim($_POST['name'] ?? '');
            if ($name === '') { flash('error', 'Вкажіть назву'); redirect('/admin/attributes?open=' . $id); }
            $slug = slugify($name);
            $taken = DB::row('SELECT id FROM attributes WHERE slug = ? AND id <> ?', [$slug, $id]);
            DB::update('attributes', [
                'name' => $name,
                'slug' => $taken ? $slug . '-' . $id : $slug,
                'unit' => trim($_POST['unit'] ?? '') ?: null,
                'type' => isset(Attrs::TYPES[$_POST['type'] ?? '']) ? $_POST['type'] : 'select',
                'filterable' => isset($_POST['filterable']) ? 1 : 0,
                'active' => isset($_POST['active']) ? 1 : 0,
                'sort' => (int)($_POST['sort'] ?? 0),
            ], 'id = ?', [$id]);
            Attrs::setCategories($id, (array)($_POST['categories'] ?? []));

            // перейменування наявних значень + нові одним полем
            foreach ((array)($_POST['value'] ?? []) as $vid => $text) {
                $text = trim((string)$text);
                if ($text === '') continue;
                DB::update('attribute_values', ['value' => $text], 'id = ? AND attribute_id = ?', [(int)$vid, $id]);
            }
            foreach ((array)($_POST['value_color'] ?? []) as $vid => $color) {
                $color = trim((string)$color);
                DB::update('attribute_values', ['color' => $color !== '' ? $color : null], 'id = ? AND attribute_id = ?', [(int)$vid, $id]);
            }
            foreach (self::parseValues($_POST['new_values'] ?? '') as $v) Attrs::ensureValue($id, $v);
            Attrs::resync($id);
            if (!$delValue) flash('success', 'Збережено');
            redirect('/admin/attributes?open=' . $id);
        }

        if ($action === 'delete' && $id) {
            $a = DB::row('SELECT name FROM attributes WHERE id = ?', [$id]);
            Attrs::deleteAttribute($id);
            flash('success', 'Характеристику «' . ($a['name'] ?? '') . '» видалено з усіх товарів');
            redirect('/admin/attributes');
        }

        redirect('/admin/attributes');
    }

    /** Значення з поля вводу: по рядку або через кому */
    private static function parseValues(string $raw): array
    {
        $parts = preg_split('~[\r\n,;]+~u', $raw) ?: [];
        $out = [];
        foreach ($parts as $p) { $p = trim($p); if ($p !== '') $out[] = $p; }
        return array_values(array_unique($out));
    }
}
