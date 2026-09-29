<?php
declare(strict_types=1);

namespace Controllers\Admin;

use View, Auth, StockWatch;

class StockRequests
{
    public static function index(): never
    {
        Auth::requireCap('products.view');
        View::show('admin/stock_requests', [
            'rows' => StockWatch::pending(),
            'page_title' => 'Очікують товар — адмінка',
        ], 'layouts/admin');
    }
}
