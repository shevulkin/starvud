<?php
/**
 * Каса: продаж, який оформлює продавець.  Запуск: php bin/cli.php test
 *
 * Головне питання такого продажу — чий це покупець. Правило одне: номер
 * телефону визначає акаунт (знайшли — беремо, ні — заводимо), а без номера
 * замовлення лишається анонімним і акаунта не вигадує. Саме це тут і доводимо,
 * бо помилка в обидва боки дорога: зайвий акаунт розриває історію покупок
 * людини, а зайве склеювання приписує їй чужу покупку.
 *
 * Друге — сам режим: чек живе у звичайному кошику сайту, і власний кошик
 * продавця має пережити чужий продаж недоторканим.
 *
 * Третє — код зі сканера має знаходити рівно ту фасовку, до якої піднесли
 * сканер: «схожий» штрихкод означає чужий товар у чеку.
 *
 * Тест створює власних користувачів і товар та прибирає їх за собою.
 */
declare(strict_types=1);

final class PosTest
{
    private int $pass = 0;
    private int $fail = 0;
    private array $userIds = [];
    private array $parentIds = [];
    private int $product = 0;
    private int $variantProduct = 0;
    private int $variant = 0;
    private int $store = 0;
    private string $notifyWas = '1';
    private array $sessionWas = [];

    public function run(): int
    {
        $stores = Catalog::stores();
        if (!$stores) { echo "  — немає активних магазинів, пропускаємо\n"; return 0; }
        $this->store = (int)$stores[0]['id'];

        $this->setUp();
        try {
            $this->testAnonymous();
            $this->testCreatesAccount();
            $this->testFindsExisting();
            $this->testKeepsName();
            $this->testCodes();
            $this->testSessionKeepsOwnCart();
            $this->testOfflineOrder();
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
        // Сесію повертаємо як була: наступні набори тестів не мають успадкувати
        // ні наш кошик, ні наш «вхід» продавцем
        $this->sessionWas = $_SESSION ?? [];

        $cat = (int)(DB::val('SELECT id FROM categories ORDER BY id LIMIT 1') ?? 0);
        $this->product = DB::insert('products', [
            'category_id' => $cat, 'name' => 'Тест: продаж із каси',
            'slug' => 'test-pos-' . bin2hex(random_bytes(3)),
            'sku' => 'TEST-POS-SKU', 'barcode' => '4820000000001',
            'base_price' => 120, 'active' => 1, 'made_to_order' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::insert('store_stock', ['product_id' => $this->product, 'variant_id' => null,
                                   'store_id' => $this->store, 'qty' => 10]);

        // Другий товар — із фасовкою: саме на ній перевіряємо, що сканер
        // потрапляє в конкретну банку, а не в «товар узагалі»
        $this->variantProduct = DB::insert('products', [
            'category_id' => $cat, 'name' => 'Тест: мед фасований',
            'slug' => 'test-pos-v-' . bin2hex(random_bytes(3)),
            'sku' => 'TEST-POS-PARENT', 'barcode' => '4820000000010',
            'base_price' => 200, 'active' => 1, 'made_to_order' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->variant = DB::insert('product_variants', [
            'product_id' => $this->variantProduct, 'name' => '0.5 л',
            'price' => 250, 'sku' => 'TEST-POS-V05', 'barcode' => '4820000000027',
            'sort' => 0, 'active' => 1,
        ]);
        // Друга фасовка потрібна, щоб вибір справді був вибором: на одній
        // каса не питає нічого (і правильно робить — див. testCodes)
        DB::insert('product_variants', [
            'product_id' => $this->variantProduct, 'name' => '1 л',
            'price' => 450, 'sku' => 'TEST-POS-V10', 'barcode' => '4820000000034',
            'sort' => 1, 'active' => 1,
        ]);
        DB::insert('store_stock', ['product_id' => $this->variantProduct, 'variant_id' => $this->variant,
                                   'store_id' => $this->store, 'qty' => 5]);
    }

    private function tearDown(): void
    {
        $_SESSION = $this->sessionWas;
        foreach ($this->parentIds as $pid) {
            foreach (DB::all('SELECT id FROM orders WHERE parent_id = ? OR id = ?', [$pid, $pid]) as $o) {
                DB::delete('order_items', 'order_id = ?', [(int)$o['id']]);
            }
            DB::delete('order_events', 'parent_id = ?', [$pid]);
            DB::delete('orders', 'parent_id = ?', [$pid]);
            DB::delete('orders', 'id = ?', [$pid]);
        }
        foreach ([$this->product, $this->variantProduct] as $pid) {
            if (!$pid) continue;
            DB::delete('store_stock', 'product_id = ?', [$pid]);
            DB::delete('product_variants', 'product_id = ?', [$pid]);
            DB::delete('products', 'id = ?', [$pid]);
        }
        foreach (array_unique($this->userIds) as $uid) DB::delete('users', 'id = ?', [$uid]);
        Settings::set('notify_all_enabled', $this->notifyWas);
    }

    private function ok(string $what, bool $cond): void
    {
        if ($cond) { $this->pass++; echo "  ok   $what\n"; }
        else { $this->fail++; echo "  FAIL $what\n"; }
    }

    private function group(string $name): void { echo "\n== $name ==\n"; }

    /** Номер, якого точно немає в базі: тест не має залежати від чужих даних */
    private function freePhone(): string
    {
        do {
            $phone = '+38067' . str_pad((string)random_int(0, 9999999), 7, '0', STR_PAD_LEFT);
        } while (DB::val('SELECT id FROM users WHERE phone = ?', [$phone]));
        return $phone;
    }

    private function testAnonymous(): void
    {
        $this->group('без номера — анонімний покупець');
        $before = (int)DB::val('SELECT COUNT(*) FROM users');
        $this->ok('номера немає — акаунта немає', Customers::resolve(null, 'Хтось') === null);
        $this->ok('порожній рядок — теж', Customers::resolve('', 'Хтось') === null);
        $this->ok('жодного користувача не заведено',
            (int)DB::val('SELECT COUNT(*) FROM users') === $before);
    }

    private function testCreatesAccount(): void
    {
        $this->group('новий номер — новий акаунт');
        $phone = $this->freePhone();
        $id = Customers::resolve($phone, 'Марія Тестова');
        $this->userIds[] = (int)$id;
        $u = DB::row('SELECT * FROM users WHERE id = ?', [$id]);
        $this->ok('акаунт створено', $u !== null);
        $this->ok('номер збережено як є', ($u['phone'] ?? '') === $phone);
        $this->ok('імʼя зі слів продавця', ($u['name'] ?? '') === 'Марія Тестова');
        $this->ok('роль — покупець', ($u['role'] ?? '') === 'customer');
        // Пошта службова: справжньої ми не знаємо, і лист на неї не піде
        $this->ok('пошта не видає себе за справжню', str_ends_with((string)($u['email'] ?? ''), '@offline.local'));
        $this->ok('у розсилку така адреса не потрапить', Newsletter::normEmail((string)$u['email']) === null);

        $again = Customers::resolve($phone, 'Марія Тестова');
        $this->ok('другий продаж на той самий номер — той самий акаунт', $again === (int)$id);
    }

    private function testFindsExisting(): void
    {
        $this->group('відомий номер — знайдений акаунт');
        $phone = $this->freePhone();
        // phone_verified_at — не деталь фікстури, а умова. Замовлення з каси
        // отримує лише той, хто номер ДОВІВ (контактом із Telegram) або кого
        // завів сам продавець. Акаунт, у якому номер просто вписали руками,
        // чужих продажів не бачить — див. Customers::ownsPhone і tests/emailauth.php.
        $existing = DB::insert('users', [
            'email' => 'pos-test-' . bin2hex(random_bytes(3)) . '@example.com',
            'name' => 'Ганна Коваль', 'role' => 'customer', 'active' => 1,
            'phone' => $phone, 'phone_verified_at' => now(), 'created_at' => now(),
        ]);
        $this->userIds[] = $existing;
        $before = (int)DB::val('SELECT COUNT(*) FROM users');
        $this->ok('замовлення лягає в наявний акаунт', Customers::resolve($phone, 'Аня') === $existing);
        $this->ok('дубля не створено', (int)DB::val('SELECT COUNT(*) FROM users') === $before);
    }

    private function testKeepsName(): void
    {
        $this->group('імʼя з трубки не затирає введене покупцем');
        $named = DB::row('SELECT * FROM users WHERE id = ?', [$this->userIds[count($this->userIds) - 1]]);
        $this->ok('своє імʼя лишилось', ($named['name'] ?? '') === 'Ганна Коваль');

        $phone = $this->freePhone();
        $blank = DB::insert('users', [
            'email' => 'pos-test-' . bin2hex(random_bytes(3)) . '@example.com',
            'name' => '', 'role' => 'customer', 'active' => 1,
            'phone' => $phone, 'phone_verified_at' => now(), 'created_at' => now(),
        ]);
        $this->userIds[] = $blank;
        Customers::resolve($phone, 'Олена');
        $this->ok('порожнє імʼя заповнюється',
            (string)DB::val('SELECT name FROM users WHERE id = ?', [$blank]) === 'Олена');
    }

    /**
     * Код зі сканера. Порядок пошуку тут не косметика: етикетку клеять на
     * конкретну банку, тож штрихкод фасовки має бити раніше за код товару.
     */
    private function testCodes(): void
    {
        $this->group('код зі сканера знаходить рівно ту фасовку');

        $byBarcode = Pos::byCode('4820000000027');
        $this->ok('штрихкод фасовки → сама фасовка',
            ($byBarcode['variant_id'] ?? null) === $this->variant);
        $this->ok('у назві видно, що саме додається',
            str_contains($byBarcode['title'] ?? '', '0.5 л'));

        $bySku = Pos::byCode('TEST-POS-V05');
        $this->ok('артикул фасовки теж працює — етикетку могло пожувати',
            ($bySku['variant_id'] ?? null) === $this->variant);

        $simple = Pos::byCode('4820000000001');
        $this->ok('товар без фасовок → сам товар',
            ($simple['product_id'] ?? null) === $this->product && $simple['variant_id'] === null);
        $this->ok('його не треба доуточнювати', ($simple['pick'] ?? true) === false);

        // Код товару, у якого фасовок кілька: додати «щось» не можна — невідомо, яку
        $parent = Pos::byCode('4820000000010');
        $this->ok('код товару з кількома фасовками просить уточнити', ($parent['pick'] ?? false) === true);

        // А от одна фасовка — це не вибір. Кошик на вітрині так само не питає
        // про неї, і каса не має бути прискіпливішою за вітрину: інакше код,
        // проставлений на товарі, не спрацював би нізащо.
        DB::update('product_variants', ['active' => 0], 'product_id = ? AND name = ?',
            [$this->variantProduct, '1 л']);
        $single = Pos::byCode('4820000000010');
        $this->ok('код товару з єдиною фасовкою кладе саме її',
            ($single['variant_id'] ?? null) === $this->variant && ($single['pick'] ?? true) === false);
        DB::update('product_variants', ['active' => 1], 'product_id = ? AND name = ?',
            [$this->variantProduct, '1 л']);

        $this->ok('невідомий код нічого не знаходить', Pos::byCode('0000000000000') === null);
        $this->ok('порожній код нічого не знаходить', Pos::byCode('  ') === null);
        // «Схожий» код — це чужий товар у чеку, тож збіг лише точний
        $this->ok('часткового збігу не буває', Pos::byCode('482000000002') === null);
    }

    /**
     * Власний кошик продавця. Він теж покупець цього магазину, і чужий продаж
     * не має стерти те, що людина відклала собі.
     */
    private function testSessionKeepsOwnCart(): void
    {
        $this->group('чужий продаж не чіпає власний кошик продавця');
        $seller = DB::row("SELECT id FROM users WHERE email LIKE 'seller@%' ORDER BY id LIMIT 1");
        if (!$seller) { echo "  — немає демо-продавця, пропускаємо\n"; return; }
        $_SESSION['user_id'] = (int)$seller['id'];

        $_SESSION['cart'] = ['own' => ['product_id' => $this->product, 'variant_id' => null, 'qty' => 7]];
        $this->ok('до продажу режим вимкнено', Pos::active() === false);

        Pos::ensure($this->store);
        $this->ok('перший товар вмикає режим', Pos::active() === true);
        $this->ok('чек починається порожнім', ($_SESSION['cart'] ?? []) === []);

        Cart::add($this->product, null, 2);
        $this->ok('товар лягає в чек', Cart::count() === 2);

        Pos::stop();
        $this->ok('після продажу режим вимкнено', Pos::active() === false);
        $this->ok('власний кошик повернувся недоторканим',
            ($_SESSION['cart']['own']['qty'] ?? 0) === 7);
        unset($_SESSION['cart']);
    }

    private function testOfflineOrder(): void
    {
        $this->group('продаж у точці проходить звичайним шляхом');
        $p = DB::row('SELECT * FROM products WHERE id = ?', [$this->product]);
        $rows = [['product' => $p, 'variant' => null, 'qty' => 2,
                  'price' => 120.0, 'old' => null, 'sum' => 240.0]];
        $stockWas = Catalog::stock($this->product, null, $this->store);

        $placed = OrderFlow::place([
            'number' => 'POS-' . bin2hex(random_bytes(3)), 'token' => bin2hex(random_bytes(8)),
            'user_id' => null, 'name' => 'Покупець', 'phone' => '', 'email' => null,
            'delivery' => 'pickup', 'city' => null, 'np_office' => null, 'address' => null,
            'comment' => null, 'store_id' => $this->store,
            'source' => 'offline', 'created_by_user_id' => null,
            'status' => 'new', 'promo_code' => null,
            'subtotal' => 240.0, 'discount' => 0, 'total' => 240.0, 'created_at' => now(),
        ], $rows, $this->store);
        $this->parentIds[] = (int)$placed['id'];

        $parent = OrderFlow::order((int)$placed['id']);
        $this->ok('замовлення без акаунта створюється', $parent !== null && $parent['user_id'] === null);
        $this->ok('порожній номер не ламає запис', ($parent['phone'] ?? null) === '');
        $this->ok('джерело збережено', ($parent['source'] ?? '') === 'offline');
        $this->ok('частина магазину знає, звідки замовлення',
            (($placed['children'][0]['source'] ?? '') === 'offline'));
        $this->ok('товар списано з точки продажу',
            Catalog::stock($this->product, null, $this->store) === $stockWas - 2);

        // Товар віддали одразу — замовлення закривається тим самим рухом
        OrderFlow::setStatus((int)$placed['children'][0]['id'], 'done', null);
        $this->ok('видача закриває й головне замовлення',
            (string)DB::val('SELECT status FROM orders WHERE id = ?', [(int)$placed['id']]) === 'done');
    }
}

return (new PosTest())->run();
