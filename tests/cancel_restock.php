<?php
/**
 * Скасування замовлення й склад.  Запуск: php bin/cli.php test
 *
 * Досі скасоване замовлення тримало товар вічно: залишок списувався при
 * оформленні й не повертався ніколи. Магазин бачив нулі там, де насправді
 * повна полиця, і сам собі забороняв продавати.
 *
 * Головне, що доводимо: повертається рівно те, що з точки колись зняли
 * (а не вся кількість позиції), повертається один раз, а зняття скасування
 * забирає товар назад — і чесно каже, якщо його вже розібрали.
 *
 * Магазини беремо наявні, а не створюємо: OrderFlow::activeStoreIds() кешує
 * список на запит, і свіжостворена точка в нього вже не потрапила б.
 */
declare(strict_types=1);

final class CancelRestockTest
{
    private int $pass = 0;
    private int $fail = 0;
    private int $productId = 0;
    private array $parentIds = [];
    private int $storeA = 0;
    private int $storeB = 0;
    private string $notifyWas = '1';
    private $defaultStoreWas = null;
    private int $waiter = 0;

    public function run(): int
    {
        $stores = Catalog::stores();
        if (count($stores) < 2) { echo "  — потрібно 2 активні магазини, пропускаємо\n"; return 0; }
        $this->storeA = (int)$stores[0]['id'];
        $this->storeB = (int)$stores[1]['id'];

        $this->setUp();
        try {
            $this->testCancelReturnsStock();
            $this->testCancelReturnsOnlyWhatWasTaken();
            $this->testCancelTwiceDoesNotDouble();
            $this->testUncancelTakesStockAgain();
            $this->testUncancelWhenStockIsGone();
            $this->testCancelWholeOrder();
            $this->testWaitingCustomerIsServed();
            $this->testDefaultStoreSetting();
            $this->testCustomerMessage();
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
        // Зміна статусу сповіщає магазин і покупця — на тесті це полетіло б
        // живим людям. Глушимо на час і повертаємо, як було.
        $this->notifyWas = (string)Settings::get('notify_all_enabled', '1');
        Settings::set('notify_all_enabled', '0');
        $this->defaultStoreWas = Settings::get('default_store_id', null);

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
            DB::delete('order_events', 'parent_id = ?', [$pid]);
            DB::delete('orders', 'parent_id = ?', [$pid]);
            DB::delete('orders', 'id = ?', [$pid]);
        }
        if ($this->productId) {
            DB::delete('stock_requests', 'product_id = ?', [$this->productId]);
            DB::delete('store_stock', 'product_id = ?', [$this->productId]);
            DB::delete('products', 'id = ?', [$this->productId]);
        }
        if ($this->waiter) {
            DB::delete('user_notify_prefs', 'user_id = ?', [$this->waiter]);
            DB::delete('users', 'id = ?', [$this->waiter]);
        }
        if ($this->defaultStoreWas === null) DB::delete('settings', '`key` = ?', ['default_store_id']);
        else Settings::set('default_store_id', (string)$this->defaultStoreWas);
        Settings::set('notify_all_enabled', $this->notifyWas);
    }

    private function ok(string $what, bool $cond): void
    {
        if ($cond) { $this->pass++; echo "  ok   $what\n"; }
        else { $this->fail++; echo "  FAIL $what\n"; }
    }

    private function group(string $name): void { echo "\n== $name ==\n"; }

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
    private function place(int $qty, ?int $pickup = null): array
    {
        $p = DB::row('SELECT * FROM products WHERE id = ?', [$this->productId]);
        $rows = [['product' => $p, 'variant' => null, 'qty' => $qty, 'price' => 100.0, 'sum' => 100.0 * $qty]];
        $placed = OrderFlow::place([
            'number' => 'TEST-' . bin2hex(random_bytes(3)), 'token' => bin2hex(random_bytes(8)),
            'user_id' => null, 'name' => 'Тест', 'phone' => '+380670000000', 'email' => null,
            'delivery' => $pickup ? 'pickup' : 'np', 'city' => 'Київ', 'np_office' => '1',
            'address' => null, 'comment' => null,
            'store_id' => $pickup, 'status' => 'new', 'promo_code' => null,
            'subtotal' => 100.0 * $qty, 'discount' => 0, 'total' => 100.0 * $qty, 'created_at' => now(),
        ], $rows, $pickup);
        $this->parentIds[] = (int)$placed['id'];
        return $placed['children'][0];
    }

    private function itemOf(int $childId): array
    {
        return DB::row('SELECT * FROM order_items WHERE order_id = ?', [$childId]) ?? [];
    }

    /**
     * Уся стрічка статусів замовлення одним рядком: після зміни частини
     * останнім лягає запис головного («оновлено автоматично»), тож дивитись
     * лише на нього означало б перевіряти не те.
     */
    private function history(int $parentId): string
    {
        $out = [];
        foreach (DB::all("SELECT message FROM order_events WHERE parent_id = ? AND type = 'status'",
                 [$parentId]) as $e) $out[] = (string)$e['message'];
        return implode("\n", $out);
    }

    private function testCancelReturnsStock(): void
    {
        $this->group('скасування повертає товар на полицю');
        $this->stock($this->storeA, 10);
        $this->stock($this->storeB, 0);
        $child = $this->place(3);
        $cid = (int)$child['id'];
        $this->ok('після оформлення залишок зменшився', $this->stockOf($this->storeA) === 7);

        OrderFlow::setStatus($cid, 'canceled', null);
        $this->ok('залишок повернувся повністю', $this->stockOf($this->storeA) === 10);
        $this->ok('частина позначена скасованою', OrderFlow::order($cid)['status'] === 'canceled');
        $this->ok('позиція більше нічого не тримає', (int)$this->itemOf($cid)['stock_taken'] === 0);
        $this->ok('скасоване не вважається нестачею', OrderFlow::shortage($cid) === []);
        $this->ok('в історії видно, що саме повернулось',
            str_contains($this->history((int)$child['parent_id']), 'Залишки повернено на склад'));
        $this->ok('головне замовлення теж скасовано',
            OrderFlow::order((int)$child['parent_id'])['status'] === 'canceled');
    }

    /** Найважливіше: замовлення понад залишок не має дарувати точці неіснуючий товар */
    private function testCancelReturnsOnlyWhatWasTaken(): void
    {
        $this->group('повертаємо рівно те, що зняли');
        $this->stock($this->storeA, 2);
        $this->stock($this->storeB, 0);
        $child = $this->place(5);          // взяли 2 з 5, бракує 3
        $cid = (int)$child['id'];
        $this->ok('склад обнулився', $this->stockOf($this->storeA) === 0);
        $this->ok('зняли рівно те, що лежало', (int)$this->itemOf($cid)['stock_taken'] === 2);

        OrderFlow::setStatus($cid, 'canceled', null);
        $this->ok('повернули 2, а не 5 замовлених', $this->stockOf($this->storeA) === 2);
    }

    private function testCancelTwiceDoesNotDouble(): void
    {
        $this->group('повторне скасування нічого не додає');
        $this->stock($this->storeA, 4);
        $this->stock($this->storeB, 0);
        $child = $this->place(1);
        $cid = (int)$child['id'];

        OrderFlow::setStatus($cid, 'canceled', null);
        $after = $this->stockOf($this->storeA);
        $again = OrderFlow::setStatus($cid, 'canceled', null);
        $this->ok('другий раз статус не змінюється', $again === false);
        $this->ok('залишок не виріс удруге', $this->stockOf($this->storeA) === $after);
        $this->ok('і дорівнює початковому', $after === 4);
    }

    private function testUncancelTakesStockAgain(): void
    {
        $this->group('зняття скасування забирає товар назад');
        $this->stock($this->storeA, 10);
        $this->stock($this->storeB, 0);
        $child = $this->place(3);
        $cid = (int)$child['id'];

        OrderFlow::setStatus($cid, 'canceled', null);
        $this->ok('після скасування на полиці все', $this->stockOf($this->storeA) === 10);

        OrderFlow::setStatus($cid, 'processing', null);
        $this->ok('товар знову зарезервовано', $this->stockOf($this->storeA) === 7);
        $this->ok('позиція знову тримає свої 3', (int)$this->itemOf($cid)['stock_taken'] === 3);
        $this->ok('нестачі немає', OrderFlow::shortage($cid) === []);
        $this->ok('в історії сказано про повторне списання',
            str_contains($this->history((int)$child['parent_id']), 'списано'));
    }

    /** Поки замовлення лежало скасованим, товар могли розібрати — і мовчати про це не можна */
    private function testUncancelWhenStockIsGone(): void
    {
        $this->group('поки лежало скасованим, товар забрали');
        $this->stock($this->storeA, 5);
        $this->stock($this->storeB, 0);
        $child = $this->place(4);
        $cid = (int)$child['id'];

        OrderFlow::setStatus($cid, 'canceled', null);
        $this->stock($this->storeA, 1);           // за цей час лишилась одна банка
        OrderFlow::setStatus($cid, 'processing', null);

        $this->ok('узяли скільки було', (int)$this->itemOf($cid)['stock_taken'] === 1);
        $this->ok('склад обнулився, а не пішов у мінус', $this->stockOf($this->storeA) === 0);
        $lack = OrderFlow::shortage($cid);
        $this->ok('нестачу пораховано', count($lack) === 1 && $lack[0]['lack'] === 3);
        $this->ok('в історії попереджено, що вже не все',
            str_contains($this->history((int)$child['parent_id']), 'вже не все'));
    }

    private function testCancelWholeOrder(): void
    {
        $this->group('скасування всього замовлення з головного');
        $this->stock($this->storeA, 6);
        $this->stock($this->storeB, 6);
        // дві точки в одному замовленні: беремо стільки, щоб жодна не закрила все
        $p = DB::row('SELECT * FROM products WHERE id = ?', [$this->productId]);
        $rows = [['product' => $p, 'variant' => null, 'qty' => 2, 'price' => 100.0, 'sum' => 200.0]];
        $placed = OrderFlow::place([
            'number' => 'TEST-' . bin2hex(random_bytes(3)), 'token' => bin2hex(random_bytes(8)),
            'user_id' => null, 'name' => 'Тест', 'phone' => '+380670000000', 'email' => null,
            'delivery' => 'np', 'city' => 'Київ', 'np_office' => '1', 'address' => null, 'comment' => null,
            'store_id' => null, 'status' => 'new', 'promo_code' => null,
            'subtotal' => 200.0, 'discount' => 0, 'total' => 200.0, 'created_at' => now(),
        ], $rows, null);
        $parentId = (int)$placed['id'];
        $this->parentIds[] = $parentId;
        $child = $placed['children'][0];
        // друга частина: передаємо позицію в іншу точку, щоб магазинів справді стало два
        $item = $this->itemOf((int)$child['id']);
        OrderFlow::transferItem((int)$item['id'], $this->storeB, null);

        $before = [$this->storeA => $this->stockOf($this->storeA), $this->storeB => $this->stockOf($this->storeB)];
        OrderFlow::setStatus($parentId, 'canceled', null);

        $this->ok('головне скасовано', OrderFlow::order($parentId)['status'] === 'canceled');
        foreach (OrderFlow::children($parentId) as $c) {
            $this->ok('частина ' . $c['number'] . ' скасована', $c['status'] === 'canceled');
        }
        $this->ok('товар повернувся тій точці, що його тримала',
            $this->stockOf($this->storeB) === $before[$this->storeB] + 2);
        $this->ok('чужої точки це не торкнулось', $this->stockOf($this->storeA) === $before[$this->storeA]);
    }

    /** Товар повернувся на полицю — а на нього чекали */
    private function testWaitingCustomerIsServed(): void
    {
        $this->group('той, хто чекав товар, дізнається про повернення');
        $this->stock($this->storeA, 1);
        $this->stock($this->storeB, 0);
        $child = $this->place(1);                 // остання банка пішла в замовлення
        $this->ok('на полиці порожньо', Catalog::stock($this->productId, null) === 0);

        $this->waiter = DB::insert('users', ['email' => 'cancel-waiter@bofu.test',
            'name' => 'Тест очікувач', 'active' => 1, 'created_at' => now()]);
        DB::insert('stock_requests', ['product_id' => $this->productId, 'variant_id' => null,
            'store_id' => null, 'user_id' => $this->waiter, 'created_at' => now()]);

        OrderFlow::setStatus((int)$child['id'], 'canceled', null);
        $req = DB::row('SELECT * FROM stock_requests WHERE user_id = ?', [$this->waiter]);
        $this->ok('очікування закрито — людині повідомили', !empty($req['notified_at']));
    }

    private function testDefaultStoreSetting(): void
    {
        $this->group('магазин за замовчуванням');
        Settings::set('default_store_id', (string)$this->storeB);
        $this->ok('вибір з налаштувань діє', OrderFlow::defaultStoreId() === $this->storeB);

        Settings::set('default_store_id', '999999');
        $this->ok('неіснуюча точка ігнорується',
            OrderFlow::defaultStoreId() === (int)Catalog::stores()[0]['id']);

        Settings::set('default_store_id', (string)$this->storeB);
        $this->stock($this->storeA, 0);
        $this->stock($this->storeB, 0);
        $child = $this->place(2);   // товару немає ніде — це «під замовлення»
        $this->ok('позицію «під замовлення» дістає обрана точка',
            (int)$child['store_id'] === $this->storeB);
        $this->ok('і з порожнього складу нічого не списано',
            (int)$this->itemOf((int)$child['id'])['stock_taken'] === 0);
    }

    /** Покупцю пишемо про його замовлення, а не про внутрішню кухню */
    private function testCustomerMessage(): void
    {
        $this->group('що бачить покупець');
        $this->stock($this->storeA, 5);
        $this->stock($this->storeB, 0);
        $child = $this->place(2);
        $parent = OrderFlow::order((int)$child['parent_id']);

        $whole = OrderFlow::customerVars($parent, 'Доставлено');
        $this->ok('номер — той, який знає людина', $whole['number'] === $parent['number']);
        $this->ok('про частини у звістці про все замовлення не йдеться',
            $whole['part'] === '' && $whole['items'] === '');

        $part = OrderFlow::customerVars($parent, 'В дорозі', OrderFlow::children((int)$parent['id'])[0]);
        $this->ok('номер лишається той самий', $part['number'] === $parent['number']);
        $this->ok('названо магазин', str_contains($part['part'], 'Частина від магазину'));
        $this->ok('перелічено саме її товари', str_contains($part['items'], 'Тестовий мед'));

        $tpl = Notify::DEFAULT_TEMPLATES['order_customer'];
        $textWhole = Notify::interpolate($tpl, $whole);
        // Дірка — це не будь-який порожній рядок, а зайвий: шаблон відділяє
        // підпис магазину порожнім рядком навмисно. Ловимо саме те, що лишається
        // від зниклих підстановок, — два й більше поспіль.
        $this->ok('порожні рядки не лишають дір у повідомленні',
            !str_contains($textWhole, "\n\n\n") && !str_ends_with($textWhole, "\n"));
        $this->ok('людина бачить статус словами',
            str_contains(mb_strtolower($textWhole), 'доставлено'));
        $this->ok('лист підписаний магазином', str_contains($textWhole, (string)cfg('app_name')));
        $this->ok('у звістці про частину видно магазин',
            str_contains(Notify::interpolate($tpl, $part), 'Частина від магазину'));

        $rules = DB::all("SELECT * FROM notification_rules WHERE event = 'order_customer'");
        $this->ok('правила події заведені', count($rules) === count(Notify::CHANNELS));
        $wrong = array_filter($rules, fn($r) => $r['recipients'] !== 'customer');
        $this->ok('усі адресовані покупцю', $wrong === []);
        $this->ok('подія позначена як адресна', Notify::isCustomerEvent('order_customer'));
        $this->ok('а звичайна — ні', !Notify::isCustomerEvent('order_new'));
    }
}

return (new CancelRestockTest())->run();
