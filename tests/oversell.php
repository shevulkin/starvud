<?php
/**
 * Заборона продажу понад залишок.  Запуск: php bin/cli.php test
 *
 * Правило навмисно вузьке: відмовляємо лише тоді, коли товару не вистачає на
 * ВСЮ мережу і його не можна виготовити. Не вистачає в одному магазині — не
 * привід відмовляти: продавець передасть позицію з іншої точки.
 *
 * Прапорець «Виготовляємо під замовлення» стоїть за замовчуванням, тож для
 * більшості каталогу нічого не змінюється — це теж перевіряємо.
 */
declare(strict_types=1);

final class OversellTest
{
    private int $pass = 0;
    private int $fail = 0;
    private int $madeToOrder = 0;
    private int $limited = 0;
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
            $this->testMadeToOrderPassesThrough();
            $this->testNetworkEnoughAcrossStores();
            $this->testBlockedWhenNetworkShort();
            $this->testPlaceRefusesInTransaction();
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
        $this->notifyWas = (string)Settings::get('notify_all_enabled', '1');
        Settings::set('notify_all_enabled', '0');
        $cat = (int)(DB::val('SELECT id FROM categories ORDER BY id LIMIT 1') ?? 0);
        $mk = function (string $name, int $mto) use ($cat): int {
            return DB::insert('products', [
                'category_id' => $cat, 'name' => $name,
                'slug' => 'test-oversell-' . bin2hex(random_bytes(3)),
                'base_price' => 100, 'active' => 1, 'made_to_order' => $mto,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        };
        $this->madeToOrder = $mk('Тест: виготовимо під замовлення', 1);
        $this->limited     = $mk('Тест: тільки зі складу', 0);
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
        foreach ([$this->madeToOrder, $this->limited] as $pid) {
            if (!$pid) continue;
            DB::delete('store_stock', 'product_id = ?', [$pid]);
            DB::delete('products', 'id = ?', [$pid]);
        }
        Settings::set('notify_all_enabled', $this->notifyWas);
    }

    private function ok(string $what, bool $cond): void
    {
        if ($cond) { $this->pass++; echo "  ok   $what\n"; }
        else { $this->fail++; echo "  FAIL $what\n"; }
    }

    private function group(string $name): void { echo "\n== $name ==\n"; }

    private function stock(int $productId, int $storeId, int $qty): void
    {
        DB::delete('store_stock', 'product_id = ? AND store_id = ?', [$productId, $storeId]);
        if ($qty > 0) DB::insert('store_stock', ['product_id' => $productId, 'variant_id' => null,
                                                 'store_id' => $storeId, 'qty' => $qty]);
    }

    /** Рядок кошика у вигляді, який дає Cart::detailed() */
    private function row(int $productId, int $qty): array
    {
        $p = DB::row('SELECT * FROM products WHERE id = ?', [$productId]);
        return ['product' => $p, 'variant' => null, 'qty' => $qty,
                'price' => 100.0, 'sum' => 100.0 * $qty];
    }

    private function testMadeToOrderPassesThrough(): void
    {
        $this->group('«під замовлення» — не обмежуємо');
        $this->stock($this->madeToOrder, $this->storeA, 0);
        $this->stock($this->madeToOrder, $this->storeB, 0);
        $this->ok('нуль на складі, а замовити можна',
            OrderFlow::unavailable([$this->row($this->madeToOrder, 10)]) === []);
    }

    private function testNetworkEnoughAcrossStores(): void
    {
        $this->group('в одній точці мало, у мережі досить');
        $this->stock($this->limited, $this->storeA, 3);
        $this->stock($this->limited, $this->storeB, 4);
        $this->ok('7 у мережі — 6 замовити можна',
            OrderFlow::unavailable([$this->row($this->limited, 6)]) === []);
        $this->ok('рівно стільки, скільки є, теж можна',
            OrderFlow::unavailable([$this->row($this->limited, 7)]) === []);
    }

    private function testBlockedWhenNetworkShort(): void
    {
        $this->group('у мережі не вистачає — відмовляємо');
        $this->stock($this->limited, $this->storeA, 2);
        $this->stock($this->limited, $this->storeB, 1);
        $short = OrderFlow::unavailable([$this->row($this->limited, 5)]);
        $this->ok('позицію відхилено', count($short) === 1);
        $this->ok('пораховано наявне', ($short[0]['have'] ?? null) === 3);
        $this->ok('пораховано бажане', ($short[0]['want'] ?? null) === 5);
        $line = OrderFlow::unavailableLine($short);
        $this->ok('повідомлення називає числа',
            str_contains($line, 'у наявності 3') && str_contains($line, 'у кошику 5'));

        $this->ok('«під замовлення» поруч не страждає',
            OrderFlow::unavailable([
                $this->row($this->limited, 5), $this->row($this->madeToOrder, 99),
            ])[0]['title'] === (string)DB::val('SELECT name FROM products WHERE id = ?', [$this->limited]));

        // неактивні точки не рятують: з них не відвантажують
        $this->stock($this->limited, $this->storeA, 2);
        $this->ok('порожній склад — відмова навіть на одну штуку',
            count(OrderFlow::unavailable([$this->row($this->limited, 99)])) === 1);
    }

    private function testPlaceRefusesInTransaction(): void
    {
        $this->group('оформлення падає, а не продає зайве');
        $this->stock($this->limited, $this->storeA, 1);
        $this->stock($this->limited, $this->storeB, 0);
        $before = (int)DB::val('SELECT COUNT(*) FROM orders');

        $threw = false;
        try {
            OrderFlow::place([
                'number' => 'OS-' . bin2hex(random_bytes(3)), 'token' => bin2hex(random_bytes(8)),
                'user_id' => null, 'name' => 'Тест', 'phone' => '+380670000003', 'email' => null,
                'delivery' => 'np', 'city' => 'Київ', 'np_office' => '1', 'address' => null,
                'comment' => null, 'store_id' => null, 'status' => 'new', 'promo_code' => null,
                'subtotal' => 500.0, 'discount' => 0, 'total' => 500.0, 'created_at' => now(),
            ], [$this->row($this->limited, 5)], null);
        } catch (RuntimeException $e) {
            $threw = true;
            $this->ok('виняток пояснює причину', str_contains($e->getMessage(), 'не вистачає'));
        }
        $this->ok('оформлення відхилено', $threw);
        $this->ok('жодного замовлення не створено',
            (int)DB::val('SELECT COUNT(*) FROM orders') === $before);
        $this->ok('склад не зачеплено',
            Catalog::stock($this->limited, null, $this->storeA) === 1);
    }
}

return (new OversellTest())->run();
