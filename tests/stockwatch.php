<?php
/**
 * «Повідомте, коли зʼявиться».  Запуск: php bin/cli.php test
 *
 * Доводимо три речі: черга не дублюється (одна людина — один запит), вона
 * закривається саме тоді, коли товар справді зʼявився, і продавець бачить
 * позиції в порядку попиту, а не в порядку надходження.
 */
declare(strict_types=1);

final class StockWatchTest
{
    private int $pass = 0;
    private int $fail = 0;
    private int $productA = 0;
    private int $productB = 0;
    private array $userIds = [];
    private int $storeId = 0;
    private string $notifyWas = '1';

    public function run(): int
    {
        $stores = Catalog::stores();
        if (!$stores) { echo "  — немає активних магазинів, пропускаємо\n"; return 0; }
        $this->storeId = (int)$stores[0]['id'];

        $this->setUp();
        try {
            $this->testAddAndDedupe();
            $this->testNotFulfilledWhileEmpty();
            $this->testFulfilOnRestock();
            $this->testQueueOrder();
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
        // Черга шле повідомлення і покупцям, і продавцям — на тесті це
        // полетіло б живим людям. Глушимо на час і повертаємо, як було.
        $this->notifyWas = (string)Settings::get('notify_all_enabled', '1');
        Settings::set('notify_all_enabled', '0');

        $cat = (int)(DB::val('SELECT id FROM categories ORDER BY id LIMIT 1') ?? 0);
        foreach (['A', 'B'] as $k) {
            $id = DB::insert('products', [
                'category_id' => $cat, 'name' => "Тестовий мед (черга $k)",
                'slug' => 'test-watch-' . strtolower($k) . '-' . bin2hex(random_bytes(3)),
                'base_price' => 100, 'active' => 1, 'made_to_order' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($k === 'A') $this->productA = $id; else $this->productB = $id;
        }
        for ($i = 0; $i < 3; $i++) {
            $this->userIds[] = DB::insert('users', [
                'name' => 'Тест черга ' . $i, 'email' => 'watch' . $i . '-' . bin2hex(random_bytes(3)) . '@bofu.local',
                'active' => 1, 'created_at' => now(),
            ]);
        }
    }

    private function tearDown(): void
    {
        foreach ([$this->productA, $this->productB] as $pid) {
            if (!$pid) continue;
            DB::delete('stock_requests', 'product_id = ?', [$pid]);
            DB::delete('store_stock', 'product_id = ?', [$pid]);
            DB::delete('products', 'id = ?', [$pid]);
        }
        foreach ($this->userIds as $uid) {
            DB::delete('user_notify_prefs', 'user_id = ?', [$uid]);
            DB::delete('users', 'id = ?', [$uid]);
        }
        Settings::set('notify_all_enabled', $this->notifyWas);
    }

    private function ok(string $what, bool $cond): void
    {
        if ($cond) { $this->pass++; echo "  ok   $what\n"; }
        else { $this->fail++; echo "  FAIL $what\n"; }
    }

    private function group(string $name): void { echo "\n== $name ==\n"; }

    private function stock(int $productId, int $qty): void
    {
        DB::delete('store_stock', 'product_id = ?', [$productId]);
        if ($qty > 0) DB::insert('store_stock', ['product_id' => $productId, 'variant_id' => null,
                                                 'store_id' => $this->storeId, 'qty' => $qty]);
    }

    private function testAddAndDedupe(): void
    {
        $this->group('одна людина — один запит');
        $this->ok('запит прийнято', StockWatch::add($this->productA, null, $this->storeId, $this->userIds[0]) === true);
        $this->ok('повторний не дублюється',
            StockWatch::add($this->productA, null, $this->storeId, $this->userIds[0]) === false);
        $this->ok('у черзі одна людина', StockWatch::waiting($this->productA, null) === 1);

        $this->ok('інша людина стає в чергу',
            StockWatch::add($this->productA, null, null, $this->userIds[1]) === true);
        $this->ok('тепер чекають двоє', StockWatch::waiting($this->productA, null) === 2);

        $this->ok('людина бачить, що вже чекає',
            StockWatch::isWaiting($this->productA, null, $this->userIds[0]) === true);
        $this->ok('хто не ставав у чергу — не чекає',
            StockWatch::isWaiting($this->productA, null, $this->userIds[2]) === false);
        $this->ok('гість не чекає ніколи', StockWatch::isWaiting($this->productA, null, null) === false);
    }

    private function testNotFulfilledWhileEmpty(): void
    {
        $this->group('поки товару немає — черга стоїть');
        $this->stock($this->productA, 0);
        $this->ok('нікому не повідомили', StockWatch::fulfil($this->productA, null) === 0);
        $this->ok('черга ціла', StockWatch::waiting($this->productA, null) === 2);
    }

    private function testFulfilOnRestock(): void
    {
        $this->group('товар зʼявився — черга закривається');
        $this->stock($this->productA, 4);
        $this->ok('повідомили обом', StockWatch::fulfil($this->productA, null) === 2);
        $this->ok('черга спорожніла', StockWatch::waiting($this->productA, null) === 0);
        $this->ok('людина більше не в черзі',
            StockWatch::isWaiting($this->productA, null, $this->userIds[0]) === false);
        $this->ok('повторний виклик нікого не турбує вдруге',
            StockWatch::fulfil($this->productA, null) === 0);

        // закритий запис лишається — за ним видно, що обіцянку виконали
        $done = (int)DB::val('SELECT COUNT(*) FROM stock_requests WHERE product_id = ? AND notified_at IS NOT NULL',
            [$this->productA]);
        $this->ok('позначку про сповіщення збережено', $done === 2);

        $this->ok('після закриття людина може стати в чергу знову',
            StockWatch::add($this->productA, null, null, $this->userIds[0]) === true);
    }

    private function testQueueOrder(): void
    {
        $this->group('черга — за попитом, не за датою');
        // B чекають троє, A (після попереднього тесту) — одна людина
        foreach ($this->userIds as $uid) StockWatch::add($this->productB, null, null, $uid);
        $this->stock($this->productB, 0);

        $rows = StockWatch::pending();
        $mine = array_values(array_filter($rows, fn($r) =>
            in_array((int)$r['product_id'], [$this->productA, $this->productB], true)));

        $this->ok('обидві позиції в черзі', count($mine) === 2);
        $this->ok('найзатребуваніша — згори', (int)$mine[0]['product_id'] === $this->productB);
        $this->ok('кількість порахована', (int)$mine[0]['cnt'] === 3);
        $this->ok('видно поточний залишок', array_key_exists('stock', $mine[0]));
        $this->ok('назва товару поруч', str_contains((string)$mine[0]['product_name'], 'черга B'));
    }
}

return (new StockWatchTest())->run();
