<?php
declare(strict_types=1);

namespace Controllers;

use View, Cart, Csrf, DB, Catalog, Bundles, Offers, Auth;

class CartController
{
    public static function index(): never
    {
        // Рядки й підсумки — з одного розрахунку: знижка набору належить
        // позиціям, і рядок кошика має показати рівно те, що ввійшло в суму
        $totals = Cart::breakdown();
        $rows = $totals['rows'];
        // Порожній кошик — це не помилка, а розвилка: людина або ще нічого не
        // обрала, або передумала. Порожній екран із одним посиланням лишає її
        // наодинці з цим рішенням; кілька позицій дають привід лишитись.
        // Запитуємо їх лише коли кошик справді порожній.
        $suggest = [];
        if (!$rows) {
            // «Може зацікавити» у порожньому кошику — про товари, а не про курси
            $suggest = DB::all("SELECT * FROM products WHERE active = 1 AND featured = 1
                                AND type <> 'course' ORDER BY id LIMIT 4");
            Catalog::preloadCards($suggest);
        }
        View::show('cart/index', [
            'rows' => $rows,
            'suggest' => $suggest,
            'totals' => $totals,
            'stores' => Catalog::stores(),
            'page_title' => 'Кошик — ' . cfg('app_name'),
        ]);
    }

    public static function add(): never
    {
        Csrf::verify();
        $pid = (int)($_POST['product_id'] ?? 0);
        $vid = (int)($_POST['variant_id'] ?? 0) ?: null;
        $qty = max(1, (int)($_POST['qty'] ?? 1));
        $p = DB::row('SELECT id, slug FROM products WHERE id = ? AND active = 1', [$pid]);

        // Коли варіант є з чого обрати — обирає покупець. Мовчки підставити перший
        // означає продати не той розмір, тож відправляємо на картку товару.
        if ($p && !self::variantChosen($pid, $vid)) {
            $to = '/product/' . $p['slug'];
            if (($_POST['ajax'] ?? '') === '1') json_response(['ok' => false, 'redirect' => url($to)]);
            flash('error', 'Оберіть варіант товару.');
            redirect($to);
        }

        $added = $p ? Cart::add($pid, $vid, $qty) : 0;
        $ajax = ($_POST['ajax'] ?? '') === '1';

        // Поклали менше, ніж просили, — це не тиха дрібниця: покупець розраховував
        // на свою кількість і має дізнатися про стелю тут, а не на оформленні.
        if ($added <= 0) {
            // «Немає в наявності» і «все наявне вже у вас у кошику» — різні речі,
            // і друге не привід шукати товар деінде
            $limit = $p ? Cart::limit($pid, $vid) : 0;
            $msg = $limit
                ? 'У кошику вже вся наявна кількість — ' . $limit . ' шт.'
                : 'Цього товару зараз немає в наявності.';
            if ($ajax) json_response(['ok' => false, 'error' => $msg]);
            flash('error', $msg);
            redirect(safe_back($_POST['back'] ?? null, '/cart'));
        }
        $note = $added < $qty
            ? 'Додали ' . $added . ' шт. — це все, що є в наявності.'
            : null;

        if ($ajax) json_response(['ok' => true, 'count' => Cart::count(), 'note' => $note]);
        flash('success', $note ?? 'Додано до кошика');
        redirect(safe_back($_POST['back'] ?? null, '/cart'));
    }

    /**
     * Зібрати набір у кошику.
     *
     * Кнопка означає «зроби так, щоб набір зібрався», а не «додай ще один
     * комплект поверх усього». Тому кладемо різницю: скільки комплектів уже
     * є — плюс один, мінус те, що вже лежить. Без цього кнопка з кошика («вам
     * бракує прополісу») додала б і другий мед, якого ніхто не просив, а з
     * картки товару поводиться як раніше: у порожньому кошику різниця дорівнює
     * повному складу.
     *
     * Фасовку, якщо в наборі вона «будь-яка», обирає набір (Bundles::expand),
     * а не покупець — інакше одна дія розсипалась би на три картки товару.
     *
     * Те, чого не вистачило на складі, не відкочує решту: покупець побачить,
     * чого саме бракує, і вирішить сам. Мовчки покласти половину набору було б
     * гірше — знижка не спрацює, а причина лишиться невидимою.
     */
    public static function addBundle(): never
    {
        Csrf::verify();
        $bundle = Bundles::find((int)($_POST['bundle_id'] ?? 0));
        $full = $bundle && $bundle['active'] ? Bundles::expand($bundle) : null;
        $back = safe_back($_POST['back'] ?? null, '/cart');

        if (!$full) {
            flash('error', 'Цього набору більше немає — щось із його складу закінчилось.');
            redirect($back);
        }

        $rows = Cart::detailed();
        $target = Bundles::setsIn($bundle, $rows) + 1;

        $short = []; $added = 0;
        foreach ($full['expanded'] as $it) {
            $want = (int)$it['qty'] * $target - Bundles::inCart($it['item'], $rows);
            if ($want <= 0) continue;   // цієї позиції вже досить
            $got = Cart::add((int)$it['product']['id'],
                $it['variant'] ? (int)$it['variant']['id'] : null, $want);
            $added += $got;
            if ($got < $want) {
                $short[] = $it['product']['name'] . ($it['variant'] ? ', ' . $it['variant']['name'] : '');
            }
        }

        if ($short) {
            flash('error', 'Набір доклали не повністю — не вистачило: ' . implode(', ', $short)
                . '. Знижка за набір спрацює, коли все буде в кошику.');
        } elseif ($added === 0) {
            // Нічого не додали, бо все вже лежить. Мовчазний редирект виглядав
            // би як кнопка, що не працює.
            flash('success', 'Набір «' . $bundle['title'] . '» уже зібраний — знижка врахована.');
        } else {
            flash('success', 'Набір «' . $bundle['title'] . '» у кошику — знижка вже врахована.');
        }
        redirect('/cart');
    }

    /**
     * Покласти в кошик домовлену ціну.
     *
     * Кількість не питаємо: вона є частиною угоди рівно так само, як число
     * гривень. «Десять по 480» не означає «одна по 480», і дозволити тут
     * вибір означало б продати за партійною ціною одну банку.
     *
     * Рядок лягає окремо від звичайного рядка того самого товару (див.
     * Cart::key): у кошику цілком законно можуть лежати десять домовлених і
     * ще дві за звичайною ціною.
     */
    public static function addOffer(): never
    {
        Csrf::verify();
        $back = safe_back($_POST['back'] ?? null, '/bargain');
        $deal = Offers::deal((int)($_POST['offer_id'] ?? 0), Auth::id());
        if (!$deal) {
            flash('error', 'Ця домовленість більше не діє. Можна домовитись наново або замовити за звичайною ціною.');
            redirect($back);
        }
        $pid = (int)$deal['product_id'];
        $vid = $deal['variant_id'] !== null ? (int)$deal['variant_id'] : null;
        $qty = (int)$deal['qty'];

        foreach (Cart::items() as $i) {
            if ((int)($i['offer_id'] ?? 0) !== (int)$deal['id']) continue;
            flash('success', 'Ця позиція вже в кошику за домовленою ціною.');
            redirect('/cart');
        }

        // Партію кладемо цілою або не кладемо взагалі. Половина партії за
        // партійною ціною — це вже інша угода, якої ніхто не укладав, і
        // домовлятись про строк решти має продавець, а не кошик.
        $limit = Cart::limit($pid, $vid);
        if ($limit !== null && $limit < $qty) {
            flash('error', 'Зараз на складі є лише ' . $limit . ' шт. із домовлених ' . $qty
                . '. Домовлена ціна діє на всю партію — напишіть нам, і ми скажемо строк.');
            redirect($back);
        }

        Cart::add($pid, $vid, $qty, (int)$deal['id']);
        flash('success', 'Позицію додано за домовленою ціною — ' . price_fmt($deal['price']) . '/шт.');
        redirect('/cart');
    }

    /** Варіант обовʼязковий лише там, де вибір справді є: один варіант — це не вибір */
    private static function variantChosen(int $productId, ?int $variantId): bool
    {
        $variants = Catalog::variants($productId);
        if (count($variants) < 2) return true;
        foreach ($variants as $v) if ((int)$v['id'] === $variantId) return true;
        return false;
    }

    public static function update(): never
    {
        Csrf::verify();
        $action = $_POST['action'] ?? '';
        $key = $_POST['key'] ?? '';
        if ($action === 'remove') Cart::remove($key);
        elseif ($action === 'inc') Cart::setQty($key, (int)($_POST['qty'] ?? 1) + 1);
        elseif ($action === 'dec') Cart::setQty($key, (int)($_POST['qty'] ?? 1) - 1);
        elseif ($action === 'set') Cart::setQty($key, (int)($_POST['qty'] ?? 1));
        redirect('/cart');
    }
}
