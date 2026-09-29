<?php
declare(strict_types=1);

namespace Controllers\Admin;

use DB, View, Auth, Csrf, OrderFlow;

/**
 * Черга розподілу онлайн-замовлень.
 *
 * Правило доступу тут те саме, що й скрізь: право перевіряється на сервері, а
 * не приховуванням пункту меню. orders.manage — це і є «керувати замовленням
 * цілком», тобто передавати позиції між магазинами; хто цього не може, тому й
 * розподіляти нема чим.
 */
class Dispatch
{
    public static function index(): never
    {
        Auth::requireCap('orders.manage');

        if (is_post()) {
            Csrf::verify();
            $id = (int)($_POST['id'] ?? 0);
            /*
             * Підтвердження — окрема дія, а не наслідок перегляду сторінки.
             *
             * Спокуса позначати розподіленим усе, що людина відкрила, є: менше
             * кліків. Але тоді черга спорожніє від самого гортання, і
             * замовлення, яке ніхто не дивився, зникне разом із рештою.
             */
            if (\Dispatch::confirm($id, Auth::id())) {
                flash('success', 'Розподіл підтверджено — магазини бачать свої частини.');
            } else {
                flash('error', 'Це замовлення вже розподілене або не існує.');
            }
            redirect('/admin/dispatch');
        }

        $orders = \Dispatch::queue();
        $children = [];
        $hints = [];
        foreach ($orders as $o) {
            $oid = (int)$o['id'];
            $children[$oid] = OrderFlow::children($oid);
            $hints[$oid] = \Dispatch::itemHints($oid);
        }

        View::show('admin/dispatch', [
            'orders' => $orders,
            'children' => $children,
            'hints' => $hints,
            'statuses' => OrderFlow::STATUSES,
            'page_title' => 'Розподіл замовлень — адмінка',
        ], 'layouts/admin');
    }
}
