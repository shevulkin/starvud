<?php
/**
 * Продаж понад залишок.  Запуск: php bin/cli.php test
 *
 * Сайт свідомо дозволяє замовити більше, ніж лежить на складі: виробник
 * доробить. Але мовчати про це не можна — хтось має або передати позицію туди,
 * де товар є, або стати за станок. Тут доводимо дві речі: нестача помічається
 * й фіксується, а передача позиції не дарує старому магазину неіснуючий товар.
 *
 * Магазини беремо наявні, а не створюємо: OrderFlow::activeStoreIds() кешує
 * список на запит, і свіжостворена точка в нього вже не потрапила б.
 */
declare(strict_types=1);

final class ShortageTest
{
    private int $pass = 0;
    private int $fail = 0;
    private int $productId = 0;
    private array $parentIds = [];
    private int $storeA = 0;
    private int $storeB = 0;
    private string $notifyWas = '1';

    public function run(): int
    {
        $stores = Catalog::stores();
        if (count($stores) < 2) { echo "  — потрібно 2 активні магазини, пропускаємо\n"; return 0; }
        $this->storeA = (int)$stores[0]['id'];
        $this->storeB = (int)$stores[1]['id'];

        $this->setUp();
        try {
            $this->testEnoughStock();
            $this->testShortageRecorded();
            $this->testTransferDoesNotInflate();
            $this->testLegacyRowsIgnored();
        } finally {
            $this->tearDown();
        }
        echo "\n" . ($this->fail === 0
            ? "УСЕ ДОБРЕ: {$this->pass} перевірок\n"
            : "ПРОВАЛЕНО: {$this->fail} з " . ($this->pass + $this->fail) . "\n");
        return $this->fail === 0 ? 0 : 1;
    }

    private function setUp(): void
    {
        // Передача позиції наприкінці сповіщає магазин — на тесті це полетіло б
        // живим людям у месенджер. Глушимо на час і повертаємо, як було.
        $this->notifyWas = (string)Settings::get('notify_all_enabled', '1');
        Settings::set('notify_all_enabled', '0');

        $cat = (int)(DB::val('SELECT id FROM categories ORDER BY id LIMIT 1') ?? 0);
        $this->productId = DB::insert('products', [
            'category_id' => $cat, 'name' => 'Тестовий мед (нестача)',
            'slug' => 'test-shortage-' . bin2hex(random_bytes(3)),
            'base_price' => 100, 'active' => 1, 'made_to_order' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function tearDown(): void
    {
        foreach ($this->parentIds as $pid) {
            foreach (DB::all('SELECT id FROM orders WHERE parent_id = ? OR id = ?', [$pid, $pid]) as $o) {
                DB::delete('order_items', 'order_id = ?', [(int)$o['id']]);
            }
            DB::delete('order_events', 'parent_id = ?', [$pid]);
            DB::delete('orders', 'parent_id = ?', [$pid]);
            DB::delete('orders', 'id = ?', [$pid]);
        }
        if ($this->productId) {
            DB::delete('store_stock', 'product_id = ?', [$this->productId]);
            DB::delete('products', 'id = ?', [$this->productId]);
        }
        Settings::set('notify_all_enabled', $this->notifyWas);
    }

    private function ok(string $what, bool $cond): void
    {
        if ($cond) { $this->pass++; echo "  ok   $what\n"; }
        else { $this->fail++; echo "  FAIL $what\n"; }
    }

    private function group(string $name): void { echo "\n== $name ==\n"; }

    /** Поставити рівно такий залишок у точці */
    private function stock(int $storeId, int $qty): void
    {
        DB::delete('store_stock', 'product_id = ? AND store_id = ?', [$this->productId, $storeId]);
        DB::insert('store_stock', ['product_id' => $this->productId, 'variant_id' => null,
                                   'store_id' => $storeId, 'qty' => $qty]);
    }

    private function stockOf(int $storeId): int
    {
        return (int)(DB::val('SELECT SUM(qty) FROM store_stock WHERE product_id = ? AND store_id = ? AND variant_id IS NULL',
            [$this->productId, $storeId]) ?? 0);
    }

    /** Оформити замовлення на $qty штук; повертає підзамовлення-виконавця */
    private function place(int $qty): array
    {
        $p = DB::row('SELECT * FROM products WHERE id = ?', [$this->productId]);
        $rows = [['product' => $p, 'variant' => null, 'qty' => $qty, 'price' => 100.0, 'sum' => 100.0 * $qty]];
        $placed = OrderFlow::place([
            'number' => 'TEST-' . bin2hex(random_bytes(3)), 'token' => bin2hex(random_bytes(8)),
            'user_id' => null, 'name' => 'Тест', 'phone' => '+380670000000', 'email' => null,
            'delivery' => 'np', 'city' => 'Київ', 'np_office' => '1', 'address' => null, 'comment' => null,
            'store_id' => null, 'status' => 'new', 'promo_code' => null,
            'subtotal' => 100.0 * $qty, 'discount' => 0, 'total' => 100.0 * $qty, 'created_at' => now(),
        ], $rows, null);
        $this->parentIds[] = (int)$placed['id'];
        return $placed['children'][0];
    }

    private function testEnoughStock(): void
    {
        $this->group('товару вистачає — мовчимо');
        $this->stock($this->storeA, 10);
        $this->stock($this->storeB, 0);
        $child = $this->place(3);
        $item = DB::row('SELECT * FROM order_items WHERE order_id = ?', [(int)$child['id']]);

        $this->ok('виконавцем став магазин із запасом', (int)$child['store_id'] === $this->storeA);
        $this->ok('списано рівно замовлене', (int)$item['stock_taken'] === 3);
        $this->ok('залишок зменшився на замовлене', $this->stockOf($this->storeA) === 7);
        $this->ok('нестачі немає', OrderFlow::shortage((int)$child['id']) === []);
        $this->ok('у сповіщенні порожньо', OrderFlow::shortageSummary((int)$child['id']) === '');
    }

    private function testShortageRecorded(): void
    {
        $this->group('замовили більше, ніж є');
        $this->stock($this->storeA, 2);
        $this->stock($this->storeB, 1);
        $child = $this->place(5);
        $cid = (int)$child['id'];
        $item = DB::row('SELECT * FROM order_items WHERE order_id = ?', [$cid]);

        $this->ok('замовлення все одно прийняте', $item !== null);
        $this->ok('узяли рівно те, що лежало', (int)$item['stock_taken'] === 2);
        $this->ok('склад обнулився, а не пішов у мінус', $this->stockOf($this->storeA) === 0);

        $lack = OrderFlow::shortage($cid);
        $this->ok('нестачу пораховано', count($lack) === 1 && $lack[0]['lack'] === 3);
        $summary = OrderFlow::shortageSummary($cid);
        $this->ok('у сповіщенні названо числа', str_contains($summary, 'є 2 з 5'));
        $this->ok('у сповіщенні сказано, що робити',
            str_contains($summary, 'Передайте') && str_contains($summary, 'довиробіть'));

        $ev = DB::all('SELECT * FROM order_events WHERE parent_id = ? AND type = ?',
            [(int)$child['parent_id'], 'shortage']);
        $this->ok('нестача лягла в історію замовлення', count($ev) === 1);
        $this->ok('в історії названо позицію й кількість',
            count($ev) === 1 && str_contains((string)$ev[0]['message'], '3 з 5'));
    }

    /**
     * Головне, заради чого все це: передача позиції, проданої понад залишок,
     * не має повертати старому магазину товар, якого в нього не було.
     */
    private function testTransferDoesNotInflate(): void
    {
        $this->group('передача не дарує неіснуючий товар');
        $this->stock($this->storeA, 2);
        $this->stock($this->storeB, 1);
        $child = $this->place(5);           // A віддав 2 зі своїх 2, бракує 3
        $cid = (int)$child['id'];
        $item = DB::row('SELECT * FROM order_items WHERE order_id = ?', [$cid]);

        $this->ok('до передачі: A порожній', $this->stockOf($this->storeA) === 0);
        OrderFlow::transferItem((int)$item['id'], $this->storeB, null);

        $this->ok('A отримав назад рівно свої 2, а не 5 замовлених',
            $this->stockOf($this->storeA) === 2);
        $this->ok('B віддав усе, що мав', $this->stockOf($this->storeB) === 0);

        $moved = DB::row('SELECT * FROM order_items WHERE id = ?', [(int)$item['id']]);
        $this->ok('списане перерахували під новий магазин', (int)$moved['stock_taken'] === 1);
        $this->ok('нестача перерахувалась: тепер бракує 4',
            (OrderFlow::shortage((int)$moved['order_id'])[0]['lack'] ?? null) === 4);

        $ev = DB::val("SELECT message FROM order_events WHERE parent_id = ? AND type = 'transfer' ORDER BY id DESC",
            [(int)$child['parent_id']]);
        $this->ok('в історії попереджено, що й у новій точці бракує',
            is_string($ev) && str_contains($ev, 'теж не вистачає'));
    }

    private function testLegacyRowsIgnored(): void
    {
        $this->group('замовлення до цього обліку');
        $this->stock($this->storeA, 10);
        $this->stock($this->storeB, 0);
        $child = $this->place(3);
        $cid = (int)$child['id'];
        DB::query('UPDATE order_items SET stock_taken = NULL WHERE order_id = ?', [$cid]);

        $this->ok('невідоме не видаємо за нестачу', OrderFlow::shortage($cid) === []);
        $this->ok('і в сповіщенні мовчимо', OrderFlow::shortageSummary($cid) === '');

        // передача такої позиції поводиться як раніше: повертає всю кількість
        $item = DB::row('SELECT * FROM order_items WHERE order_id = ?', [$cid]);
        $before = $this->stockOf($this->storeA);
        OrderFlow::transferItem((int)$item['id'], $this->storeB, null);
        $this->ok('старій точці повернули всю позицію', $this->stockOf($this->storeA) === $before + 3);
    }
}

return (new ShortageTest())->run();
