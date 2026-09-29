<?php
declare(strict_types=1);

namespace Controllers\Admin;

use View, Auth, Offers;

/**
 * Черга торгу.
 *
 * Сторінка відповідає на одне питання продавця: на що я маю відповісти
 * просто зараз. Тому першою вкладкою йде «хід за нами», а не всі розмови
 * поспіль: рядок, який нічого не потребує, лише відсуває вниз той, що
 * потребує.
 */
class OffersAdmin
{
    private const TABS = [
        'todo'  => 'Чекають відповіді',
        'wait'  => 'Відповіли — чекаємо на покупця',
        'deals' => 'Домовились',
        'all'   => 'Усі',
    ];

    public static function index(): never
    {
        Auth::requireCap('offers.manage');
        if (is_post()) self::act();

        $tab = (string)($_GET['tab'] ?? 'todo');
        if (!isset(self::TABS[$tab])) $tab = 'todo';

        View::show('admin/offers', [
            'rows' => Offers::queue($tab),
            'tab' => $tab, 'tabs' => self::TABS,
            'todo' => Offers::todoCount(),
            'page_title' => 'Торг — адмінка',
        ], 'layouts/admin');
    }

    /**
     * Дія над розмовою. Право перевіряється тут ще раз, а не лише на маршруті:
     * один маршрут обслуговує і показ, і зміну, а сховати кнопку — не перевірка.
     */
    private static function act(): void
    {
        Auth::requireCap('offers.manage');
        $id = (int)($_POST['offer_id'] ?? 0);
        $note = (string)($_POST['note'] ?? '');
        $staff = (int)Auth::id();

        $res = match ((string)($_POST['do'] ?? '')) {
            'accept'  => Offers::accept($id, 'seller', $staff),
            'counter' => Offers::counter($id, (int)($_POST['qty'] ?? 0),
                            (float)str_replace(',', '.', (string)($_POST['price'] ?? '0')), $note, $staff),
            'decline' => Offers::decline($id, 'seller', $note, $staff),
            default   => ['ok' => false, 'error' => 'Невідома дія.'],
        };

        flash($res['ok'] ? 'success' : 'error', $res['ok']
            ? 'Відповідь надіслано покупцю.'
            : (string)$res['error']);
        redirect('/admin/offers?tab=' . urlencode((string)($_POST['tab'] ?? 'todo')));
    }
}
