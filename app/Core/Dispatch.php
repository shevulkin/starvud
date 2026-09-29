<?php
declare(strict_types=1);

/**
 * Розподіл онлайн-замовлень: хто з магазинів що відправляє.
 *
 * ЩО ТУТ ВИРІШУЄТЬСЯ І ЧОМУ ЦЕ НЕ РОБИТЬ АВТОМАТ
 *
 * Магазин для кожної позиції обирає система при оформленні — за залишком
 * (OrderFlow::pickStore). Це рішення дешеве й майже завжди правильне, але воно
 * сліпе: автомат не знає, що одна точка сьогодні без пакувальника, що покупцю
 * зручніше отримати все однією посилкою, і що з двох точок із товаром одна
 * ближча. Тому вибір лишається, але тепер його переглядає людина.
 *
 * ЧОМУ АВТОМАТИЧНИЙ ВИБІР НЕ ПРИБРАНО
 *
 * Спокуса є: не призначати магазин узагалі, хай вирішує розподільник. Але
 * списання складу відбувається в момент оформлення, під блокуванням рядків, —
 * саме воно не дає двом покупцям купити останню банку в ту саму секунду.
 * Замовлення без магазину нема з чого списувати, і резерв зникає.
 *
 * Тому схема така: система призначає ОДРАЗУ (резерв тримається) → розподільник
 * переглядає й перекладає, якщо треба → підтверджує. Для більшості замовлень
 * він просто погодиться, і вони не чекатимуть на нього ні секунди.
 *
 * ЩО САМЕ ПОТРАПЛЯЄ В ЧЕРГУ
 *
 * Лише те, де розподіл узагалі має сенс: замовлення з сайту, які треба везти.
 * Самовивіз розподіляти нема чого — магазин обрав сам покупець. Курс і поготів:
 * його ніхто нікуди не везе. Продажі, оформлені продавцем (каса, телефон), теж
 * не сюди: там магазин відомий з тієї миті, як продавець їх завів.
 */
class Dispatch
{
    /** Способи доставки, за яких хтось має вирішити, звідки везти */
    private const SHIPPED = ['np', 'other'];

    /**
     * Замовлення, які чекають на розподіл.
     *
     * @param int $limit стеля вибірки — черга не має перетворюватись на дамп
     *                   усієї бази, якщо ніхто не заходив тиждень
     */
    public static function queue(int $limit = 200): array
    {
        $in = implode(',', array_fill(0, count(self::SHIPPED), '?'));
        // Найстаріші вгорі: чекають довше, і саме вони псують враження
        return DB::all(
            "SELECT * FROM orders
              WHERE parent_id IS NULL AND routed_at IS NULL
                AND source = 'site' AND delivery IN ($in)
                AND status NOT IN ('canceled', 'done')
              ORDER BY created_at LIMIT " . max(1, $limit), self::SHIPPED);
    }

    /**
     * Скільки чекає — для позначки в меню.
     *
     * Число в пункті меню тут єдине, що нагадує про чергу саме: замовлення,
     * помічене через день, це день, який покупець прочекав дарма.
     */
    public static function todoCount(): int
    {
        $in = implode(',', array_fill(0, count(self::SHIPPED), '?'));
        return (int)DB::val(
            "SELECT COUNT(*) FROM orders
              WHERE parent_id IS NULL AND routed_at IS NULL
                AND source = 'site' AND delivery IN ($in)
                AND status NOT IN ('canceled', 'done')", self::SHIPPED);
    }

    /** Чи це замовлення взагалі підлягає розподілу (для картки замовлення) */
    public static function needsRouting(array $order): bool
    {
        return ($order['parent_id'] ?? null) === null
            && ($order['routed_at'] ?? null) === null
            && (string)($order['source'] ?? 'site') === 'site'
            && in_array((string)($order['delivery'] ?? ''), self::SHIPPED, true)
            && !in_array((string)($order['status'] ?? ''), ['canceled', 'done'], true);
    }

    /**
     * Підтвердити розподіл: далі замовлення живе як звичайне.
     *
     * Повторний виклик нічого не змінює — підтверджує той, хто перший
     * подивився, і переписувати його імʼя другим натисканням нема за що.
     */
    public static function confirm(int $parentId, ?int $userId): bool
    {
        $o = DB::row('SELECT * FROM orders WHERE id = ? AND parent_id IS NULL', [$parentId]);
        if (!$o || ($o['routed_at'] ?? null) !== null) return false;
        DB::update('orders', ['routed_at' => now(), 'routed_by' => $userId], 'id = ?', [$parentId]);
        OrderFlow::log($parentId, $userId, 'note', 'Розподіл підтверджено: '
            . self::storeLine($parentId) . '.');
        return true;
    }

    /** «Львів — 2 позиції, Шепетівка — 1 позиція» для журналу й списку */
    public static function storeLine(int $parentId): string
    {
        $rows = DB::all(
            'SELECT COALESCE(s.name, "Без магазину") AS store, COUNT(i.id) AS n
               FROM orders o
               LEFT JOIN stores s ON s.id = o.store_id
               LEFT JOIN order_items i ON i.order_id = o.id
              WHERE o.parent_id = ? GROUP BY o.id, s.name ORDER BY s.name', [$parentId]);
        $parts = [];
        foreach ($rows as $r) {
            $parts[] = $r['store'] . ' — ' . plural_n((int)$r['n'], 'позиція', 'позиції', 'позицій');
        }
        return $parts ? implode(', ', $parts) : 'без частин';
    }

    /**
     * Підказки для рішення: де скільки лежить і чи не розходиться ціна точки
     * з тією, за якою продали.
     *
     * Саме те, чого розподільнику бракує найбільше. Без залишків по мережі
     * «передати позицію» — здогадка; без цін — непомітна втрата виторгу тієї
     * точки, яка продає дорожче за вітрину.
     *
     * @return array<int,array{title:string,qty:int,price:float,stores:array,mismatch:array}>
     */
    public static function itemHints(int $parentId): array
    {
        $out = [];
        $items = DB::all(
            'SELECT i.*, o.store_id FROM order_items i JOIN orders o ON o.id = i.order_id
              WHERE o.parent_id = ? OR o.id = ? ORDER BY i.id', [$parentId, $parentId]);
        $stores = DB::all('SELECT id, name, city FROM stores WHERE active = 1 ORDER BY sort, id');

        foreach ($items as $it) {
            $pid = (int)$it['product_id'];
            $vid = $it['variant_id'] !== null ? (int)$it['variant_id'] : null;
            $stock = Catalog::stockByStore($pid, $vid);
            $paid = (float)$it['price'];

            $rows = [];
            $mismatch = [];
            foreach ($stores as $s) {
                $sid = (int)$s['id'];
                $own = DB::val($vid === null
                    ? 'SELECT price FROM store_prices WHERE product_id = ? AND store_id = ? AND variant_id IS NULL'
                    : 'SELECT price FROM store_prices WHERE product_id = ? AND store_id = ? AND variant_id = ?',
                    $vid === null ? [$pid, $sid] : [$pid, $sid, $vid]);
                $rows[] = [
                    'id' => $sid,
                    'name' => $s['name'] . ($s['city'] ? ', ' . $s['city'] : ''),
                    'qty' => (int)($stock[$sid] ?? 0),
                    'own_price' => $own === null ? null : (float)$own,
                    'current' => (int)($it['store_id'] ?? 0) === $sid,
                ];
                // Розбіжність цікава лише для точки, якій позиція дісталась:
                // решта її не продає, і попереджати про них — шум
                if ((int)($it['store_id'] ?? 0) === $sid && $own !== null && abs((float)$own - $paid) >= 0.01) {
                    $mismatch = ['store' => $s['name'], 'own' => (float)$own, 'paid' => $paid];
                }
            }
            $out[] = [
                'id' => (int)$it['id'],
                'title' => (string)$it['title'] . ($it['variant_name'] ? ' · ' . $it['variant_name'] : ''),
                'qty' => (int)$it['qty'],
                'price' => $paid,
                'stores' => $rows,
                'mismatch' => $mismatch,
            ];
        }
        return $out;
    }
}
