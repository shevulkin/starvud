<?php
declare(strict_types=1);

namespace Controllers;

use Auth, Cart, Csrf, Pos;

/**
 * Смужка продажу на вітрині.
 *
 * Живе поза /admin навмисно — так само, як режим редагування контенту: чек
 * набирають, ходячи сайтом, і кнопки смужки мають працювати саме там, де
 * продавець зараз стоїть, а не тільки в адмінці.
 */
class PosController
{
    /** Підсумок чека для смужки: кошик міг змінитись без перезавантаження сторінки */
    public static function state(): never
    {
        if (!Pos::active()) json_response(['active' => false]);
        $totals = Pos::totals();
        json_response([
            'active' => true,
            'count' => Cart::count(),
            'total' => $totals['total'],
            'total_label' => price_fmt($totals['total']),
            'customer' => Pos::label(),
        ]);
    }

    /** Скасувати продаж: чек зникає, власний кошик продавця повертається */
    public static function off(): never
    {
        Csrf::verify();
        if (!Auth::can('orders.create')) redirect('/');
        Pos::stop();
        flash('success', 'Продаж скасовано, чек порожній.');
        redirect(safe_back($_POST['back'] ?? null, '/'));
    }
}
