<?php
declare(strict_types=1);

namespace Controllers\Admin;

use DB, View, Auth;

class Dashboard
{
    public static function index(): never
    {
        // Адмін рахує замовлення цілком (головні), продавець — свої підзамовлення:
        // рахувати обидва рівні разом означало б подвоїти і кількість, і оборот.
        $storeIds = Auth::storeIds();
        $in = $storeIds ? implode(',', array_map('intval', $storeIds)) : '0';
        $where = Auth::can('orders.manage') ? 'parent_id IS NULL' : "parent_id IS NOT NULL AND store_id IN ($in)";
        $stats = [
            'orders_new' => (int)DB::val("SELECT COUNT(*) FROM orders WHERE status = 'new' AND $where"),
            'orders_total' => (int)DB::val("SELECT COUNT(*) FROM orders WHERE $where"),
            'products' => (int)DB::val('SELECT COUNT(*) FROM products WHERE active = 1'),
            'revenue' => (float)(DB::val("SELECT COALESCE(SUM(total),0) FROM orders WHERE status != 'canceled' AND $where") ?? 0),
        ];
        $recent = DB::all("SELECT * FROM orders WHERE $where ORDER BY id DESC LIMIT 8");
        View::show('admin/dashboard', [
            'stats' => $stats, 'recent' => $recent,
            'page_title' => 'Панель — ' . cfg('app_name'),
        ], 'layouts/admin');
    }
}
