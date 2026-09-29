<?php
declare(strict_types=1);

namespace Controllers\Admin;

use DB, View, Auth, Catalog, Bundles;

/**
 * Набори «разом дешевше».
 *
 * Свій екран, а не рядок серед акцій: в акції одна прив'язка, а в набору —
 * склад, тобто список товарів, який у таблицю акцій не вміщається й не має
 * туди вміщатись. Редагується один набір за раз, як товар: склад правлять
 * рідко, а бачити його треба цілком.
 */
class BundlesAdmin
{
    public static function index(): never
    {
        Auth::requireCap('promos.manage');

        if (is_post()) {
            $action = $_POST['_action'] ?? '';

            if ($action === 'add' && trim($_POST['title'] ?? '') !== '') {
                $id = DB::insert('bundles', [
                    'title' => trim($_POST['title']),
                    'kind' => ($_POST['kind'] ?? '') === 'fixed' ? 'fixed' : 'percent',
                    'value' => self::value($_POST['kind'] ?? '', $_POST['value'] ?? ''),
                    'active' => 1,
                    'sort' => (int)DB::val('SELECT COALESCE(MAX(sort),0)+1 FROM bundles'),
                ]);
                // Склад приймаємо одразу: набір без товарів не існує як річ,
                // і крок «створіть спершу порожній» був би вигаданим.
                $errors = self::saveItems($id);
                foreach ($errors as $err) flash('error', $err);
                Bundles::forget();
                flash('success', $errors || !Bundles::find($id)['items']
                    ? 'Набір створено — лишилось додати до нього товари'
                    : 'Набір створено');
                redirect('/admin/bundles?id=' . $id);
            }

            if ($action === 'save') {
                $id = (int)($_POST['id'] ?? 0);
                $b = Bundles::find($id);
                if ($b) {
                    DB::update('bundles', [
                        'title' => trim($_POST['title'] ?? $b['title']) ?: $b['title'],
                        'kind' => ($_POST['kind'] ?? '') === 'fixed' ? 'fixed' : 'percent',
                        'value' => self::value($_POST['kind'] ?? '', $_POST['value'] ?? ''),
                        'active' => isset($_POST['active']) ? 1 : 0,
                        'sort' => (int)($_POST['sort'] ?? $b['sort']),
                    ], 'id = ?', [$id]);
                    foreach (self::saveItems($id) as $err) flash('error', $err);
                    Bundles::forget();
                    flash('success', 'Збережено');
                }
                redirect('/admin/bundles?id=' . $id);
            }

            if ($action === 'delete') {
                $id = (int)($_POST['id'] ?? 0);
                DB::delete('bundle_items', 'bundle_id = ?', [$id]);
                DB::delete('bundles', 'id = ?', [$id]);
                Bundles::forget();
                flash('success', 'Набір видалено');
                redirect('/admin/bundles');
            }

            redirect('/admin/bundles');
        }

        $list = Bundles::all(false);
        $current = null;
        $id = (int)($_GET['id'] ?? 0);
        if ($id) $current = Bundles::find($id);
        // Без явного вибору відкриваємо перший: порожній екран зі списком
        // ліворуч і нічим праворуч не пояснює, що тут роблять
        if (!$current && $list) $current = Bundles::find((int)$list[0]['id']);

        View::show('admin/bundles', [
            'list' => $list,
            'b' => $current,
            // Розгорнутий склад із цінами: у наборі головне не перелік назв,
            // а різниця між «окремо» і «разом», і побачити її треба тут, а не
            // здогадуватись, відкривши сайт
            'preview' => $current ? Bundles::expand($current) : null,
            'products' => DB::all('SELECT id, name FROM products WHERE active = 1 ORDER BY name'),
            'variants' => self::variantMap(),
            'page_title' => 'Набори — адмінка',
        ], 'layouts/admin');
    }

    /**
     * Значення знижки. Відсоток тримаємо в межах 0–99: сто відсотків — це
     * «безкоштовно», і такий набір роздав би склад. Фіксована ціна межі не
     * має, крім невідʼємності: набір за 500 і набір за 5000 однаково законні.
     */
    private static function value($kind, $raw): float
    {
        $v = (float)str_replace(',', '.', trim((string)$raw));
        return $kind === 'fixed' ? max(0.0, $v) : max(0.0, min(99.0, $v));
    }

    /**
     * Склад набору з форми. Перезаписується цілком — як оптова шкала й з тієї
     * ж причини: список короткий, власної історії не має, а звіряти два
     * переліки порядково нічого не дає.
     *
     * @return string[] що відкинули
     */
    private static function saveItems(int $bundleId): array
    {
        $errors = [];
        $rows = [];
        foreach ((array)($_POST['item'] ?? []) as $it) {
            $pid = (int)($it['product_id'] ?? 0);
            if (!$pid) continue;   // порожній рядок — незаповнений запас у формі

            $p = DB::row('SELECT id, name FROM products WHERE id = ?', [$pid]);
            if (!$p) { $errors[] = 'Товар зі списку зник — рядок пропущено.'; continue; }

            $vid = (int)($it['variant_id'] ?? 0);
            if ($vid && !DB::row('SELECT 1 FROM product_variants WHERE id = ? AND product_id = ?', [$vid, $pid])) {
                // Фасовка від іншого товару — це підроблена форма або застарілий
                // вибір. Мовчки лишати її не можна: набір ніколи б не зібрався.
                $errors[] = '«' . $p['name'] . '»: обрана фасовка не належить цьому товару — узято будь-яку.';
                $vid = 0;
            }
            $qty = max(1, (int)($it['qty'] ?? 1));

            // Той самий товар у наборі двічі — це не дві позиції, а описка:
            // складаємо кількості, інакше набір вимагав би того самого окремо
            $key = $pid . ':' . $vid;
            $rows[$key] = ['product_id' => $pid, 'variant_id' => $vid ?: null,
                           'qty' => ($rows[$key]['qty'] ?? 0) + $qty];
        }

        if (count($rows) === 1) {
            // Набір із однієї позиції — це не набір, а знижка на товар: для неї
            // є акція, і саме там її видно як акцію.
            //
            // Але й стерти склад через це не можна. Найімовірніша причина —
            // випадково очищений рядок, і тоді відмова зберегти повертає
            // людину до того, що було, а перезапис порожнім забрав би й другу
            // позицію разом із першою.
            return ['У наборі має бути принаймні два різні товари — інакше це звичайна акція. Склад не змінено.'];
        }

        DB::tx(function () use ($bundleId, $rows) {
            DB::delete('bundle_items', 'bundle_id = ?', [$bundleId]);
            foreach ($rows as $r) DB::insert('bundle_items', ['bundle_id' => $bundleId] + $r);
        });

        return $errors;
    }

    /** Фасовки всіх товарів одним запитом: [product_id => [{id,name}, ...]] */
    private static function variantMap(): array
    {
        $out = [];
        foreach (DB::all('SELECT id, product_id, name FROM product_variants WHERE active = 1 ORDER BY sort, id') as $v) {
            $out[(int)$v['product_id']][] = ['id' => (int)$v['id'], 'name' => (string)$v['name']];
        }
        return $out;
    }
}
