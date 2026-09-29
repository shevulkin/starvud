<?php
declare(strict_types=1);

namespace Controllers\Admin;

use DB, View, Auth, Catalog, Settings, QtyDiscounts;

/**
 * Оптові знижки: шкали за кількістю.
 *
 * Своя сторінка, а не блок серед акцій. Не через розмір: у шкал є питання,
 * якого немає в акцій, — котра з них справді діє. Яруси перебивають один
 * одного цілком, тому заповнена шкала може не діяти ні на що, і сказати це
 * може лише число поруч із нею. Стовпець «діє на N товарів» на сторінці
 * акцій не помістився б, а тут — головне, заради чого сторінка існує.
 */
class Wholesale
{
    public static function index(): never
    {
        Auth::requireCap('promos.manage');

        if (is_post()) {
            // Стеля живе тут разом зі шкалою: саме вона вирішує, скільки з
            // набраних відсотків покупець побачить у кошику насправді.
            $cap = trim((string)($_POST['max_discount_default'] ?? ''));
            if ($cap !== '') {
                Settings::set('max_discount_default',
                    (string)max(0.0, min(100.0, (float)str_replace(',', '.', $cap))));
            }
            $cat = (int)($_POST['tier_category_id'] ?? 0);
            $errors = QtyDiscounts::save(null, $cat ?: null, (array)($_POST['tier'] ?? []));
            foreach ($errors as $err) flash('error', $err);
            if (!$errors) flash('success', 'Знижки за кількість збережено');
            redirect('/admin/wholesale' . ($cat ? '?tier_cat=' . $cat : ''));
        }

        $tierCat = (int)($_GET['tier_cat'] ?? 0);
        View::show('admin/wholesale', [
            'categories' => Catalog::categories(),
            'tier_cat' => $tierCat,
            'tier_rows' => QtyDiscounts::level(null, $tierCat ?: null),
            'ladders' => self::ladders(),
            'page_title' => 'Оптові знижки — адмінка',
        ], 'layouts/admin');
    }

    /**
     * Усі заповнені шкали разом із тим, скільки товарів кожна справді зачіпає.
     *
     * «Справді» — ключове слово. Шкалу розділу перебиває шкала товару, а
     * загальну — обидві, тож заповнений ярус і ярус, що діє, — різні речі.
     * Без цього числа сторінка показувала б намір, а не наслідок: власник
     * бачив би загальну шкалу на місці й не розумів, чому вона мовчить.
     *
     * Рахуємо в памʼяті, а не запитами: шкали й дерево розділів уже
     * закешовані Catalog, тож ціна всього — один SELECT по товарах.
     *
     * @return array<int,array{key:string,label:string,tiers:array,uses:int,link:?string}>
     */
    private static function ladders(): array
    {
        $out = [];

        $global = QtyDiscounts::level(null, null);
        if ($global) {
            $out['global'] = ['key' => 'global', 'label' => 'Увесь магазин',
                              'tiers' => $global, 'uses' => 0, 'link' => null];
        }

        foreach (DB::all('SELECT q.category_id, c.name FROM qty_discounts q
                          JOIN categories c ON c.id = q.category_id
                          WHERE q.product_id IS NULL AND q.category_id IS NOT NULL
                          GROUP BY q.category_id, c.name ORDER BY c.sort, c.name') as $r) {
            $cid = (int)$r['category_id'];
            $out['c' . $cid] = ['key' => 'c' . $cid, 'label' => 'Розділ «' . $r['name'] . '»',
                                'tiers' => QtyDiscounts::level(null, $cid), 'uses' => 0,
                                'link' => '/admin/wholesale?tier_cat=' . $cid];
        }

        foreach (DB::all('SELECT q.product_id, p.name FROM qty_discounts q
                          JOIN products p ON p.id = q.product_id
                          WHERE q.product_id IS NOT NULL
                          GROUP BY q.product_id, p.name ORDER BY p.name') as $r) {
            $pid = (int)$r['product_id'];
            $out['p' . $pid] = ['key' => 'p' . $pid, 'label' => 'Товар «' . $r['name'] . '»',
                                'tiers' => QtyDiscounts::level($pid, null), 'uses' => 0,
                                'link' => '/admin/products/' . $pid];
        }

        if (!$out) return [];

        // Проганяємо кожен активний товар тим самим розвʼязанням, що й кошик:
        // інакше сторінка обіцяла б одне, а покупець бачив інше.
        foreach (DB::all('SELECT id, category_id, wholesale FROM products WHERE active = 1') as $p) {
            $r = Catalog::qtyResolve($p);
            if (!$r['tiers']) continue;
            $key = match ($r['level']) {
                'product'  => 'p' . (int)$p['id'],
                'category' => 'c' . (int)$r['category_id'],
                'global'   => 'global',
                default    => null,
            };
            if ($key !== null && isset($out[$key])) $out[$key]['uses']++;
        }

        return array_values($out);
    }
}
