<?php
declare(strict_types=1);

/**
 * «Повідомте, коли зʼявиться».
 *
 * Дві сторони одного факту. Покупцю це обіцянка: не доведеться щодня заходити
 * й перевіряти. Продавцю — сигнал попиту: скільки людей уже готові купити те,
 * чого немає. Друге важливіше: черга очікувань показує, що варто виробити
 * першим, і це єдине місце в системі, де видно попит на відсутній товар.
 *
 * Запит завжди належить акаунту. Канали сповіщень людина обирає в кабінеті
 * (Notify::optionsFor), а в гостя кабінету немає — тож і запит йому нема куди
 * привʼязати. Це не обмеження, а наслідок: вхід тут у два кліки.
 */
class StockWatch
{
    /** Умова «той самий варіант» — NULL не порівнюється через = */
    private static function variantCond(?int $variantId): array
    {
        return $variantId === null
            ? ['variant_id IS NULL', []]
            : ['variant_id = ?', [$variantId]];
    }

    /**
     * Записати очікування. Повертає false, якщо ця людина вже чекає цю позицію —
     * тоді ні дубля, ні повторного сповіщення продавцю.
     */
    public static function add(int $productId, ?int $variantId, ?int $storeId, int $userId): bool
    {
        [$cond, $args] = self::variantCond($variantId);
        $exists = DB::val(
            "SELECT id FROM stock_requests
              WHERE product_id = ? AND user_id = ? AND notified_at IS NULL AND $cond",
            array_merge([$productId, $userId], $args));
        if ($exists) return false;

        DB::insert('stock_requests', [
            'product_id' => $productId, 'variant_id' => $variantId,
            'store_id' => $storeId, 'user_id' => $userId, 'created_at' => now(),
        ]);
        self::tellStaff($productId, $variantId, $storeId);
        return true;
    }

    /** Скільки людей чекають цю позицію просто зараз */
    public static function waiting(int $productId, ?int $variantId): int
    {
        [$cond, $args] = self::variantCond($variantId);
        return (int)DB::val(
            "SELECT COUNT(*) FROM stock_requests WHERE product_id = ? AND notified_at IS NULL AND $cond",
            array_merge([$productId], $args));
    }

    /** Чи вже чекає саме ця людина — щоб не пропонувати їй те саме вдруге */
    public static function isWaiting(int $productId, ?int $variantId, ?int $userId): bool
    {
        if (!$userId) return false;
        [$cond, $args] = self::variantCond($variantId);
        return (bool)DB::val(
            "SELECT 1 FROM stock_requests
              WHERE product_id = ? AND user_id = ? AND notified_at IS NULL AND $cond LIMIT 1",
            array_merge([$productId, $userId], $args));
    }

    /**
     * Товар зʼявився — розсилаємо тим, хто чекав, і закриваємо запити.
     * Повертає, скільком повідомили.
     *
     * Закриваємо одразу й безумовно: інакше список перетворюється на смітник,
     * у якому продавець перестає бачити справжній попит. Товар можуть розібрати
     * за годину — це прийнятно; бронювання за очікуванням це вже інша задача.
     */
    public static function fulfil(int $productId, ?int $variantId): int
    {
        if (Catalog::stock($productId, $variantId) <= 0) return 0;

        [$cond, $args] = self::variantCond($variantId);
        $rows = DB::all(
            "SELECT * FROM stock_requests WHERE product_id = ? AND notified_at IS NULL AND $cond",
            array_merge([$productId], $args));
        if (!$rows) return 0;

        $p = DB::row('SELECT * FROM products WHERE id = ?', [$productId]);
        if (!$p) return 0;

        $vars = [
            'product' => self::title($p, $variantId),
            'where' => self::whereAvailable($productId, $variantId),
            'url' => self::productUrl($p),
        ];
        $sent = 0;
        foreach ($rows as $r) {
            Notify::toUser((int)$r['user_id'], 'stock_back', $vars);
            DB::update('stock_requests', ['notified_at' => now()], 'id = ?', [(int)$r['id']]);
            $sent++;
        }
        return $sent;
    }

    /**
     * Черга очікувань для адмінки: рядок на позицію, найзатребуваніші згори.
     * Саме в такому порядку їх і варто виробляти.
     */
    public static function pending(): array
    {
        $rows = DB::all(
            'SELECT r.product_id, r.variant_id, COUNT(*) AS cnt, MIN(r.created_at) AS since,
                    p.name AS product_name, p.slug, v.name AS variant_name
               FROM stock_requests r
               JOIN products p ON p.id = r.product_id
          LEFT JOIN product_variants v ON v.id = r.variant_id
              WHERE r.notified_at IS NULL
           GROUP BY r.product_id, r.variant_id, p.name, p.slug, v.name
           ORDER BY cnt DESC, since ASC');
        foreach ($rows as &$r) {
            $r['stock'] = Catalog::stock((int)$r['product_id'],
                $r['variant_id'] !== null ? (int)$r['variant_id'] : null);
        }
        return $rows;
    }

    // ────────────────────────────────────────────────────────────── допоміжне

    /** Продавцю: не «клієнт просить», а скільки людей уже чекають */
    private static function tellStaff(int $productId, ?int $variantId, ?int $storeId): void
    {
        $p = DB::row('SELECT * FROM products WHERE id = ?', [$productId]);
        if (!$p) return;
        $store = $storeId ? (DB::val('SELECT name FROM stores WHERE id = ?', [$storeId]) ?? '') : '';
        Notify::fire('stock_wanted', [
            'product' => self::title($p, $variantId),
            'waiting' => (string)self::waiting($productId, $variantId),
            'store' => $store !== '' ? $store : 'будь-яка',
        ], $storeId);
    }

    private static function title(array $product, ?int $variantId): string
    {
        $title = (string)$product['name'];
        if ($variantId) {
            $v = DB::val('SELECT name FROM product_variants WHERE id = ?', [$variantId]);
            if ($v) $title .= ', ' . $v;
        }
        return $title;
    }

    /** «Є в: Київ (3 шт.), Львів (1 шт.)» — щоб людина одразу знала, куди їхати */
    private static function whereAvailable(int $productId, ?int $variantId): string
    {
        $bits = [];
        foreach (Catalog::stores() as $s) {
            $qty = Catalog::stock($productId, $variantId, (int)$s['id']);
            if ($qty > 0) $bits[] = ($s['city'] ?: $s['name']) . ' — ' . $qty . ' шт.';
        }
        return $bits ? 'Є в: ' . implode(', ', $bits) : '';
    }

    /** Абсолютне посилання разом із підписом — або порожньо, якщо адреси немає */
    private static function productUrl(array $product): string
    {
        $site = BotAuth::siteUrl();
        if ($site === '' || empty($product['slug'])) return '';
        return 'Замовити: ' . $site . '/product/' . $product['slug'];
    }
}
