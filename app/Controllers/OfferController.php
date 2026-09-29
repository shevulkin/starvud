<?php
declare(strict_types=1);

namespace Controllers;

use DB, View, Csrf, Auth, Offers, Catalog;

/**
 * Торг з боку покупця.
 *
 * Тільки для тих, хто увійшов, — з тієї ж причини, що й черга очікувань:
 * відповідь продавця має куди прийти, а канали людина обирає в кабінеті.
 * Гостю кажемо це прямо: мовчазна відмова виглядала б як поламана кнопка.
 */
class OfferController
{
    /** Моя пропозиція: перша або чергова в тій самій розмові */
    public static function propose(): never
    {
        Csrf::verify();
        $back = safe_back($_POST['back'] ?? null, '/shop');
        if (!Auth::check()) {
            flash('error', 'Увійдіть, щоб запропонувати свою ціну — інакше нам нікуди надіслати відповідь.');
            redirect($back);
        }

        $pid = (int)($_POST['product_id'] ?? 0);
        $vid = (int)($_POST['variant_id'] ?? 0) ?: null;
        $qty = (int)($_POST['qty'] ?? 0);
        // Кому зручніше назвати суму за партію, ніж ціну за штуку, — рахуємо
        // самі. Питання «а скільки це за банку» покупець собі не ставить: він
        // думає бюджетом, а не прайсом.
        $price = self::money($_POST['price'] ?? '');
        if (($_POST['mode'] ?? 'unit') === 'total' && $qty > 0 && $price !== null) {
            $price = round($price / $qty, 2);
        }
        if ($price === null) {
            flash('error', 'Вкажіть ціну числом, наприклад 480 або 480,50.');
            redirect($back);
        }

        $res = Offers::propose($pid, $vid, (int)Auth::id(), $qty, $price, (string)($_POST['note'] ?? ''));
        flash($res['ok'] ? 'success' : 'error', $res['ok']
            ? 'Пропозицію надіслано. Відповімо якнайшвидше — стежте у «Мої пропозиції».'
            : $res['error']);
        redirect($back);
    }

    /** «Згоден» на умови продавця */
    public static function accept(): never
    {
        Csrf::verify();
        $back = safe_back($_POST['back'] ?? null, '/bargain');
        if (!Auth::check()) redirect('/');
        $res = Offers::accept((int)($_POST['offer_id'] ?? 0), 'buyer', null, (int)Auth::id());
        flash($res['ok'] ? 'success' : 'error', $res['ok']
            ? 'Домовились. Ціна закріплена за вами — покладіть позицію в кошик і оформлюйте замовлення.'
            : $res['error']);
        redirect($back);
    }

    /** «Передумав» — закрити свою ж розмову */
    public static function cancel(): never
    {
        Csrf::verify();
        $back = safe_back($_POST['back'] ?? null, '/bargain');
        if (!Auth::check()) redirect('/');
        $res = Offers::decline((int)($_POST['offer_id'] ?? 0), 'buyer',
            (string)($_POST['note'] ?? ''), null, (int)Auth::id());
        flash($res['ok'] ? 'success' : 'error',
            $res['ok'] ? 'Пропозицію закрито.' : $res['error']);
        redirect($back);
    }

    /** Кабінет: усі мої розмови про ціну */
    public static function index(): never
    {
        if (!Auth::check()) { flash('error', 'Увійдіть, щоб бачити свої пропозиції.'); redirect('/'); }
        $rows = Offers::forUser((int)Auth::id());
        // Ціна вітрини просто зараз — щоб поруч із домовленою було видно, від
        // чого саме домовились. За тиждень вона могла й змінитись.
        foreach ($rows as &$r) {
            $p = DB::row('SELECT * FROM products WHERE id = ?', [(int)$r['product_id']]);
            $v = $r['variant_id'] ? DB::row('SELECT * FROM product_variants WHERE id = ?', [(int)$r['variant_id']]) : null;
            $r['list_now'] = $p ? Catalog::price($p, $v)[0] : null;
        }
        View::show('account/offers', [
            'offers' => $rows,
            'page_title' => 'Мої пропозиції ціни — ' . cfg('app_name'),
        ]);
    }

    /**
     * Число з поля ціни. Приймаємо і крапку, і кому, і пробіли між тисячами:
     * людина друкує так, як звикла писати гроші, а не так, як зручно PHP.
     */
    private static function money($raw): ?float
    {
        $s = str_replace([' ', "\u{00A0}", ' '], '', trim((string)$raw));
        $s = str_replace(',', '.', $s);
        if ($s === '' || !is_numeric($s)) return null;
        $v = round((float)$s, 2);
        return $v > 0 ? $v : null;
    }
}
