<?php
/**
 * Скасоване замовлення не витрачає промокод.  Запуск: php bin/cli.php test
 *
 * Ліміт коду має рахувати покупки, а не спроби. Найболючіше це працювало на
 * одноразових кодах: замовлення скасували через пʼять хвилин, а код зникав
 * назавжди. Тут доводимо, що використання знімається при скасуванні —
 * і повертається, якщо замовлення повернули в роботу.
 */
declare(strict_types=1);

final class PromoCancelTest
{
    private int $pass = 0;
    private int $fail = 0;
    private int $productId = 0;
    private array $parentIds = [];
    private string $code = '';
    private string $notifyWas = '1';

    public function run(): int
    {
        if (!Catalog::stores()) { echo "  — немає активних магазинів, пропускаємо\n"; return 0; }
        $this->setUp();
        try {
            $this->testCancelReleases();
            $this->testReopenRestores();
            $this->testPartialCancelKeeps();
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
        // зміна статусу сповіщає магазин і покупця — на тесті глушимо
        $this->notifyWas = (string)Settings::get('notify_all_enabled', '1');
        Settings::set('notify_all_enabled', '0');

        $this->code = 'TESTCANCEL' . strtoupper(bin2hex(random_bytes(2)));
        DB::insert('promo_codes', ['code' => $this->code, 'percent' => 10, 'active' => 1,
                                   'expires_at' => null, 'max_uses' => 1, 'stackable' => 1]);
        $cat = (int)(DB::val('SELECT id FROM categories ORDER BY id LIMIT 1') ?? 0);
        $this->productId = DB::insert('products', [
            'category_id' => $cat, 'name' => 'Тестовий мед (скасування)',
            'slug' => 'test-cancel-' . bin2hex(random_bytes(3)),
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
            DB::delete('promo_uses', 'order_id = ?', [$pid]);
            DB::delete('order_events', 'parent_id = ?', [$pid]);
            DB::delete('orders', 'parent_id = ?', [$pid]);
            DB::delete('orders', 'id = ?', [$pid]);
        }
        $row = DB::row('SELECT id FROM promo_codes WHERE code = ?', [$this->code]);
        if ($row) DB::delete('promo_uses', 'promo_id = ?', [(int)$row['id']]);
        DB::delete('promo_codes', 'code = ?', [$this->code]);
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

    private function promoId(): int
    {
        return (int)DB::val('SELECT id FROM promo_codes WHERE code = ?', [$this->code]);
    }

    /**
     * Кожна група починає з нуля використань: інакше вони накопичуються від
     * попередніх замовлень цього ж запуску, і перевірка «рівно одне» бреше.
     */
    private function reset(): void
    {
        DB::delete('promo_uses', 'promo_id = ?', [$this->promoId()]);
    }

    /** Оформити замовлення з промокодом; повертає ['parent'=>id, 'children'=>[…]] */
    private function place(): array
    {
        $p = DB::row('SELECT * FROM products WHERE id = ?', [$this->productId]);
        $rows = [['product' => $p, 'variant' => null, 'qty' => 1, 'price' => 100.0, 'sum' => 100.0]];
        $placed = OrderFlow::place([
            'number' => 'TC-' . bin2hex(random_bytes(3)), 'token' => bin2hex(random_bytes(8)),
            'user_id' => null, 'name' => 'Тест', 'phone' => '+380670000001', 'email' => null,
            'delivery' => 'np', 'city' => 'Київ', 'np_office' => '1', 'address' => null, 'comment' => null,
            'store_id' => null, 'status' => 'new', 'promo_code' => $this->code,
            'subtotal' => 100.0, 'discount' => 10.0, 'total' => 90.0, 'created_at' => now(),
        ], $rows, null);
        $parentId = (int)$placed['id'];
        $this->parentIds[] = $parentId;
        // те саме, що робить Checkout::submit після оформлення
        Promo::recordUse(DB::row('SELECT * FROM promo_codes WHERE code = ?', [$this->code]),
            $parentId, null, '+380670000001');
        return ['parent' => $parentId, 'children' => $placed['children']];
    }

    private function testCancelReleases(): void
    {
        $this->group('скасували — код вільний');
        $this->reset();
        $o = $this->place();
        $this->ok('після оформлення використання зараховано',
            Promo::usedTotal($this->promoId()) === 1);
        $this->ok('одноразовий код більше не діє',
            Promo::check($this->code, null, '+380670000002')[0] === null);

        OrderFlow::setStatus($o['parent'], 'canceled');

        $this->ok('замовлення скасоване',
            (OrderFlow::order($o['parent'])['status'] ?? '') === 'canceled');
        $this->ok('використання знято', Promo::usedTotal($this->promoId()) === 0);
        $this->ok('кодом знову можна скористатись',
            Promo::check($this->code, null, '+380670000002')[0] !== null);
    }

    private function testReopenRestores(): void
    {
        $this->group('повернули в роботу — використання теж');
        $this->reset();
        $o = $this->place();
        OrderFlow::setStatus($o['parent'], 'canceled');
        $this->ok('після скасування вільно', Promo::usedTotal($this->promoId()) === 0);

        // магазин передумав і повернув свою частину в роботу
        OrderFlow::setStatus((int)$o['children'][0]['id'], 'processing');

        $this->ok('замовлення знову в роботі',
            (OrderFlow::order($o['parent'])['status'] ?? '') === 'processing');
        $this->ok('використання відновлено', Promo::usedTotal($this->promoId()) === 1);
        $this->ok('дубля не з’явилось',
            (int)DB::val('SELECT COUNT(*) FROM promo_uses WHERE order_id = ?', [$o['parent']]) === 1);
    }

    private function testPartialCancelKeeps(): void
    {
        $this->group('замовлення живе — код витрачений');
        $this->reset();
        $o = $this->place();
        $this->ok('використання зараховано', Promo::usedTotal($this->promoId()) === 1);

        // рух статусу, який не є скасуванням, нічого не звільняє
        OrderFlow::setStatus((int)$o['children'][0]['id'], 'shipped');
        $this->ok('код лишається витраченим', Promo::usedTotal($this->promoId()) === 1);
        OrderFlow::setStatus((int)$o['children'][0]['id'], 'done');
        $this->ok('і після доставки теж', Promo::usedTotal($this->promoId()) === 1);
    }
}

return (new PromoCancelTest())->run();
