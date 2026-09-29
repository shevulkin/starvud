<?php
declare(strict_types=1);

/**
 * Режим продажу: продавець набирає замовлення за покупця.
 *
 * Форма зі списком товарів тут не годиться. Продавець не знає каталогу
 * напамʼять — він ходить магазином (або сайтом) і бере те, що бачить, або
 * підносить сканер до етикетки. Тому режим влаштований, як режим редагування
 * контенту (EditMode): вмикається один раз, живе в сесії, показує смужку внизу
 * екрана й не заважає ходити сайтом як завжди.
 *
 * Кошиком у цьому режимі служить звичайний кошик сайту — той самий, у який
 * кладуть покупці. Так працює все, що вже написано: кнопки «У кошик», ліміти
 * залишку, варіанти, ціни. Власний кошик продавця відкладається вбік на час
 * продажу й повертається, коли режим вимкнено: чужа покупка не має стирати
 * те, що людина відклала собі.
 *
 * Покупця обирають на вході (Новий продаж): анонім, знайдений за номером або
 * створений тут же. Номер лишається головним іменем покупця — див. Customers.
 */
class Pos
{
    private const KEY = 'pos';
    private const OWN_CART = 'pos_own_cart';

    /** Чи людина взагалі може продавати за покупця */
    public static function available(): bool { return Auth::can('orders.create'); }

    /**
     * Чи режим увімкнено зараз. Право перевіряємо тут, а не лише при вмиканні:
     * позначка в сесії пережила б і зміну ролі, і режим перегляду «як покупець».
     */
    public static function active(): bool
    {
        return !empty($_SESSION[self::KEY]) && self::available();
    }

    /** @return ?array{store_id:int,source:string,user_id:?int,phone:?string,name:string,started_at:string} */
    public static function data(): ?array
    {
        return self::active() ? $_SESSION[self::KEY] : null;
    }

    /**
     * Почати продаж. Власний кошик продавця відкладаємо: він повернеться, коли
     * продаж завершиться або його скасують.
     */
    public static function start(int $storeId, string $source, ?int $userId, ?string $phone, string $name): void
    {
        $_SESSION[self::OWN_CART] = $_SESSION['cart'] ?? [];
        unset($_SESSION['cart']);
        $_SESSION[self::KEY] = [
            'store_id' => $storeId,
            'source' => in_array($source, ['phone', 'offline'], true) ? $source : 'offline',
            'user_id' => $userId,
            'phone' => $phone,
            'name' => trim($name),
            'started_at' => now(),
        ];
    }

    /**
     * Продаж починається з першого товару, а не з відкриття екрана.
     *
     * Зазирнути на касу, нічого не продавши, — звичайна дія: подивитись ціну,
     * перевірити залишок. Вона не має ні ховати власний кошик продавця, ні
     * вмикати смужку продажу на весь сайт.
     */
    public static function ensure(int $storeId): void
    {
        if (self::active()) return;
        self::start($storeId, 'offline', null, null, '');
    }

    /** Змінити точку продажу вже під час набору: у неї свій цінник і свій склад */
    public static function setStore(int $storeId): void
    {
        if (self::active()) $_SESSION[self::KEY]['store_id'] = $storeId;
    }

    /** Спосіб продажу: у точці чи телефоном */
    public static function setSource(string $source): void
    {
        if (self::active() && in_array($source, ['phone', 'offline'], true)) {
            $_SESSION[self::KEY]['source'] = $source;
        }
    }

    /** Змінити дані покупця вже під час продажу (номер знайшовся не одразу) */
    public static function setCustomer(?int $userId, ?string $phone, string $name): void
    {
        if (!self::active()) return;
        $_SESSION[self::KEY]['user_id'] = $userId;
        $_SESSION[self::KEY]['phone'] = $phone;
        $_SESSION[self::KEY]['name'] = trim($name);
    }

    /**
     * Завершити режим: набране зникає, власний кошик продавця повертається.
     * Викликається і після оформлення замовлення, і при скасуванні — обидва
     * рази має статися рівно те саме.
     */
    public static function stop(): void
    {
        $own = $_SESSION[self::OWN_CART] ?? [];
        unset($_SESSION[self::KEY], $_SESSION[self::OWN_CART]);
        if ($own) $_SESSION['cart'] = $own;
        else unset($_SESSION['cart']);
    }

    public static function storeId(): int
    {
        $d = self::data();
        return $d ? (int)$d['store_id'] : 0;
    }

    /** Хто покупець, одним рядком для смужки */
    public static function label(): string
    {
        $d = self::data();
        if (!$d) return '';
        $parts = array_filter([$d['name'] ?: '', $d['phone'] ?: '']);
        return $parts ? implode(' · ', $parts) : 'Анонімний покупець';
    }

    /** Підсумки набраного — за цінником магазину-продавця */
    public static function totals(): array
    {
        return Cart::total(self::storeId() ?: null);
    }

    public static function count(): int { return Cart::count(); }

    /**
     * Позиція за кодом — те, що прилітає зі сканера або вводять руками.
     *
     * Сканер працює як клавіатура: набирає код і тисне Enter. Окремого
     * «драйвера» не потрібно — потрібне поле, куди цей код прилетить, і пошук
     * за точним збігом.
     *
     * Порядок пошуку не випадковий:
     *   1) штрихкод фасовки — етикетку клеять на конкретну банку;
     *   2) штрихкод товару — коли фасовок немає;
     *   3) артикул фасовки, 4) артикул товару — те саме, але нашим кодом,
     *      бо його вводять руками, коли етикетка пошкоджена.
     *
     * Збіг лише точний: «схожий» штрихкод — це чужий товар у чеку.
     *
     * @return array{product_id:int,variant_id:?int,title:string,pick:bool}|null
     *         pick = код товару, у якого є фасовки: яку саме — невідомо
     */
    public static function byCode(string $code): ?array
    {
        $code = trim($code);
        if ($code === '') return null;

        foreach (['barcode', 'sku'] as $field) {
            $v = DB::row("SELECT pv.id, pv.product_id, pv.name, p.name AS product_name
                          FROM product_variants pv JOIN products p ON p.id = pv.product_id
                          WHERE pv.$field = ? AND pv.active = 1 AND p.active = 1
                          ORDER BY pv.id LIMIT 1", [$code]);
            if ($v) {
                return ['product_id' => (int)$v['product_id'], 'variant_id' => (int)$v['id'],
                        'title' => $v['product_name'] . ', ' . $v['name'], 'pick' => false];
            }
            $p = DB::row("SELECT id, name FROM products WHERE $field = ? AND active = 1 ORDER BY id LIMIT 1", [$code]);
            if ($p) {
                $pid = (int)$p['id'];
                $variants = Catalog::variants($pid);
                // Одна фасовка — це не вибір, а просто фасовка. Кошик на сайті
                // так само не питає про неї (CartController::variantChosen), і
                // каса не має бути прискіпливішою за вітрину.
                if (count($variants) === 1) {
                    return ['product_id' => $pid, 'variant_id' => (int)$variants[0]['id'],
                            'title' => $p['name'] . ', ' . $variants[0]['name'], 'pick' => false];
                }
                return ['product_id' => $pid, 'variant_id' => null, 'title' => (string)$p['name'],
                        'pick' => count($variants) > 1];
            }
        }
        return null;
    }

    /**
     * Позиція, чий код відрізняється від сканованого лише останньою цифрою.
     *
     * Найчастіша причина «код не знайдено» — не сканер, а описка при введенні:
     * остання цифра штрихкоду рахується з попередніх, і помилитись у ній
     * найлегше. Замість глухого «немає такого» показуємо, де саме шукати.
     *
     * @return ?string назва позиції
     */
    public static function nearMiss(string $code): ?string
    {
        $code = trim($code);
        if (!preg_match('/^\d{8}$|^\d{13}$/', $code)) return null;
        $like = substr($code, 0, -1) . '_';

        $v = DB::row('SELECT pv.name, p.name AS product_name FROM product_variants pv
                      JOIN products p ON p.id = pv.product_id
                      WHERE pv.barcode LIKE ? LIMIT 1', [$like]);
        if ($v) return $v['product_name'] . ', ' . $v['name'];

        $p = DB::val('SELECT name FROM products WHERE barcode LIKE ? LIMIT 1', [$like]);
        return $p !== null ? (string)$p : null;
    }

    /**
     * Плитка товарів для екрана продажу: по рядку на кожну фасовку.
     *
     * Асортимент на 30 позицій цілком влазить в один екран, і тап по плитці —
     * найшвидший спосіб набрати чек: без пошуку, без сканера, без ходіння
     * сайтом. Ціна й залишок — тієї точки, від імені якої продають.
     */
    public static function tiles(?int $storeId, ?int $categoryId = null): array
    {
        $where = ['p.active = 1'];
        $args = [];
        if ($categoryId) {
            // гілка розділу: продавець тисне «Мед» і бачить весь мед, а не лише
            // те, що не розклали по сортах
            [$cond, $branch] = Catalog::branchSql($categoryId);
            $where[] = $cond;
            foreach ($branch as $b) $args[] = $b;
        }
        $products = DB::all('SELECT p.* FROM products p WHERE ' . implode(' AND ', $where)
            . ' ORDER BY p.name', $args);

        $out = [];
        foreach ($products as $p) {
            $variants = Catalog::variants((int)$p['id']);
            foreach ($variants ?: [null] as $v) {
                [$price] = Catalog::price($p, $v, $storeId);
                $stock = Catalog::stockByStore((int)$p['id'], $v ? (int)$v['id'] : null);
                $out[] = [
                    'product_id' => (int)$p['id'],
                    'variant_id' => $v ? (int)$v['id'] : 0,
                    'title' => (string)$p['name'],
                    'variant_name' => $v ? (string)$v['name'] : '',
                    'photo' => Catalog::photo($p, $v),
                    'price' => $price,
                    'stock' => $storeId ? (int)($stock[$storeId] ?? 0) : (int)array_sum($stock),
                    'made_to_order' => !empty($p['made_to_order']),
                ];
            }
        }
        return $out;
    }

    /**
     * Рядки чека — той самий формат, що й у кошика покупця (Cart::detailed):
     * саме його чекають OrderFlow::place(), Promo::cut() і перевірка залишків.
     */
    public static function lines(?int $storeId = null): array
    {
        return Cart::detailed($storeId ?: (self::storeId() ?: null));
    }
}
