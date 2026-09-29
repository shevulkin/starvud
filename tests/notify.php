<?php
/**
 * Особисті налаштування сповіщень.  Запуск: php bin/cli.php test
 *
 * Головне, що доводимо: вибір користувача — це фільтр ПІД налаштуванням адміна.
 * Він може лише прибрати зайве й ніколи не здатен увімкнути канал чи подію,
 * які адміністратор закрив.
 *
 * Тест підміняє глобальні перемикачі й правила, а наприкінці повертає їх
 * точно як було, тож на робочі налаштування не впливає.
 */
declare(strict_types=1);

final class NotifyTest
{
    private const KEYS = ['notify_all_enabled', 'notify_telegram_enabled',
        'notify_email_enabled', 'notify_push_enabled', 'notify_viber_enabled'];

    private int $pass = 0;
    private int $fail = 0;
    private array $settings = [];
    private array $rules = [];
    private int $admin = 0;
    private int $customer = 0;

    public function run(): int
    {
        $this->setUp();
        try {
            $this->testDefaults();
            $this->testUserPicksChannelNotEvents();
            $this->testUserCanTurnOff();
            $this->testUserCannotTurnOnWhatAdminClosed();
            $this->testLegacyPerEventRowsCleared();
            $this->testAdminSwitchesWin();
            $this->testGroups();
            $this->testPushOfferedToStaffOnly();
            $this->testOrderMessage();
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
        foreach (self::KEYS as $k) $this->settings[$k] = Settings::get($k, null);
        // recipients теж: перевірки міняють адресата правила, і без відновлення
        // тест тихо переписав би робочі налаштування сповіщень
        $this->rules = DB::all('SELECT id, enabled, recipients FROM notification_rules');
        foreach (self::KEYS as $k) Settings::set($k, '1');

        // лишаємо ввімкненим рівно одне правило: order_new через telegram
        DB::query('UPDATE notification_rules SET enabled = 0');
        $row = DB::row("SELECT id FROM notification_rules WHERE event = 'order_new' AND channel = 'telegram'");
        if ($row) DB::update('notification_rules', ['enabled' => 1, 'recipients' => 'admins_sellers'], 'id = ?', [$row['id']]);

        $this->admin = DB::insert('users', ['email' => 'notify-admin@bofu.test', 'name' => 'Тест адмін',
            'active' => 1, 'tg_chat_id' => '123', 'created_at' => now()]);
        DB::insert('user_roles', ['user_id' => $this->admin, 'role' => Roles::ADMIN, 'created_at' => now()]);
        $this->customer = DB::insert('users', ['email' => 'notify-buyer@bofu.test', 'name' => 'Тест покупець',
            'active' => 1, 'created_at' => now()]);
        Auth::forgetRoles();
        Notify::forgetPrefs();
    }

    private function tearDown(): void
    {
        foreach ($this->settings as $k => $v) {
            if ($v === null) DB::delete('settings', '`key` = ?', [$k]);
            else Settings::set($k, $v);
        }
        foreach ($this->rules as $r) {
            DB::update('notification_rules',
                ['enabled' => (int)$r['enabled'], 'recipients' => $r['recipients']], 'id = ?', [$r['id']]);
        }
        foreach ([$this->admin, $this->customer] as $id) {
            DB::delete('user_notify_prefs', 'user_id = ?', [$id]);
            DB::delete('user_roles', 'user_id = ?', [$id]);
            DB::delete('users', 'id = ?', [$id]);
        }
        Auth::forgetRoles();
        Notify::forgetPrefs();
    }

    private function ok(string $what, bool $cond): void
    {
        if ($cond) { $this->pass++; echo "  ok   $what\n"; }
        else { $this->fail++; echo "  FAIL $what\n"; }
    }

    private function group(string $name): void { echo "\n== $name ==\n"; }

    private function user(int $id): array { return DB::row('SELECT * FROM users WHERE id = ?', [$id]); }

    // ── Перевірки ───────────────────────────────────────────────────────────

    private function testDefaults(): void
    {
        $this->group('за замовчуванням людина отримує все дозволене');
        $this->ok('порожні налаштування = згода', Notify::wants($this->admin, 'order_new', 'telegram'));
        $opts = Notify::optionsFor($this->user($this->admin));
        $this->ok('пропонується рівно ввімкнене адміном правило',
            array_keys($opts) === ['order_new'] && array_keys($opts['order_new']) === ['telegram']);
        $this->ok('канал позначено готовим — chat_id заданий', $opts['order_new']['telegram']['ready']);
    }

    private function testUserPicksChannelNotEvents(): void
    {
        $this->group('людина обирає спосіб отримання, а не події');
        $chans = Notify::channelsFor($this->user($this->admin));
        $this->ok('у кабінеті пропонуються канали', array_keys($chans) === ['telegram']);
        $this->ok('канал позначено готовим — chat_id заданий', $chans['telegram']['ready']);
        $this->ok('поруч сказано, що саме приходитиме',
            $chans['telegram']['events'] === ['Нове замовлення']);
    }

    private function testUserCanTurnOff(): void
    {
        $this->group('людина може вимкнути канал цілком');
        Notify::saveChannels($this->user($this->admin), []);   // жодної галки
        Notify::forgetPrefs();
        $this->ok('вимкнений канал більше не хоче', !Notify::wantsChannel($this->admin, 'telegram'));
        $this->ok('і жодна подія цим каналом не піде',
            !Notify::wants($this->admin, 'order_new', 'telegram')
            && !Notify::wants($this->admin, 'order_status', 'telegram'));
        $this->ok('у БД один рядок — про канал, не про подію',
            (int)DB::val('SELECT COUNT(*) FROM user_notify_prefs WHERE user_id = ? AND event = ? AND channel = ?',
                [$this->admin, Notify::ANY_EVENT, 'telegram']) === 1);

        Notify::saveChannels($this->user($this->admin), ['telegram' => '1']);
        Notify::forgetPrefs();
        $this->ok('повернути назад можна', Notify::wantsChannel($this->admin, 'telegram'));
        $this->ok('зайвих рядків не лишається',
            (int)DB::val('SELECT COUNT(*) FROM user_notify_prefs WHERE user_id = ?', [$this->admin]) === 0);
    }

    private function testUserCannotTurnOnWhatAdminClosed(): void
    {
        $this->group('вибір не може вийти за межі дозволеного адміном');
        // підроблена форма: просимо канал, якого адмін не давав
        Notify::saveChannels($this->user($this->admin), ['telegram' => '1', 'email' => '1']);
        Notify::forgetPrefs();
        $this->ok('закритий адміном канал не потрапив у налаштування',
            (int)DB::val("SELECT COUNT(*) FROM user_notify_prefs WHERE channel = 'email'") === 0);
        $this->ok('і не зʼявляється у виборі',
            !isset(Notify::channelsFor($this->user($this->admin))['email']));
        $opts = Notify::optionsFor($this->user($this->admin));
        $this->ok('закрита адміном подія теж не зʼявляється', !isset($opts['stock_low']));
    }

    /** Старий вибір по подіях не має глушити канал, який людина щойно ввімкнула */
    private function testLegacyPerEventRowsCleared(): void
    {
        $this->group('рядки старого формату прибираються');
        DB::insert('user_notify_prefs', ['user_id' => $this->admin, 'event' => 'order_new',
                                         'channel' => 'telegram', 'enabled' => 0]);
        Notify::forgetPrefs();
        Notify::saveChannels($this->user($this->admin), ['telegram' => '1']);
        Notify::forgetPrefs();
        $this->ok('стара заборона по події зникла',
            (int)DB::val("SELECT COUNT(*) FROM user_notify_prefs WHERE user_id = ? AND event = 'order_new'",
                [$this->admin]) === 0);
        $this->ok('канал працює', Notify::wants($this->admin, 'order_new', 'telegram'));
    }

    private function testAdminSwitchesWin(): void
    {
        $this->group('перемикачі адміна головніші за вибір людини');
        Settings::set('notify_telegram_enabled', '0');
        $opts = Notify::optionsFor($this->user($this->admin));
        $this->ok('вимкнений канал зникає з вибору', !isset($opts['order_new']['telegram']));
        Settings::set('notify_telegram_enabled', '1');

        Settings::set('notify_all_enabled', '0');
        $this->ok('головний перемикач прибирає все', Notify::optionsFor($this->user($this->admin)) === []);
        Settings::set('notify_all_enabled', '1');
    }

    private function testGroups(): void
    {
        $this->group('групи отримувачів');
        $this->ok('адмін входить у admins_sellers', Notify::inGroup($this->admin, 'admins_sellers'));
        $this->ok('покупець не входить', !Notify::inGroup($this->customer, 'admins_sellers'));
        $this->ok('покупцю нема чого налаштовувати',
            Notify::optionsFor($this->user($this->customer)) === []
            && Notify::channelsFor($this->user($this->customer)) === []);
    }

    /**
     * Push пропонуємо лише тим, хто може підписатися. Підписка живе в
     * адмінпанелі, а Api::pushSubscribe() відповідає стороннім 403 — покупцю
     * галка означала б обіцянку, якої нема кому виконати.
     */
    private function testPushOfferedToStaffOnly(): void
    {
        $this->group('«сповіщення в браузері» — лише персоналу');
        $rule = DB::row("SELECT id FROM notification_rules WHERE event = 'order_customer' AND channel = 'push'");
        if (!$rule) { echo "  — правила order_customer/push немає, пропускаємо\n"; return; }
        DB::update('notification_rules', ['enabled' => 1, 'recipients' => 'customer'], 'id = ?', [$rule['id']]);

        $this->ok('покупцю push не пропонується',
            !isset(Notify::channelsFor($this->user($this->customer))['push']));
        $this->ok('і в матриці подій його теж немає',
            !isset(Notify::optionsFor($this->user($this->customer))['order_customer']['push']));

        // те саме правило, але адресоване персоналу — адміну push доступний
        DB::update('notification_rules', ['recipients' => 'admins_sellers'], 'id = ?', [$rule['id']]);
        $this->ok('персоналу — доступний',
            isset(Notify::channelsFor($this->user($this->admin))['push']));

        DB::update('notification_rules', ['enabled' => 0], 'id = ?', [$rule['id']]);
    }

    /**
     * Повідомлення про замовлення. Адреса доставки — те, заради чого продавець
     * і відкриває сповіщення; а самовивіз адреси не має, і порожнього рядка
     * замість неї бути не повинно.
     */
    private function testOrderMessage(): void
    {
        $this->group('повідомлення про замовлення');
        $np = ['delivery' => 'np', 'city' => 'Київ', 'np_office' => 'Відділення №5', 'address' => null];
        $other = ['delivery' => 'other', 'city' => 'Ніжин', 'np_office' => null, 'address' => 'вул. Шевченка, 1'];
        $pickup = ['delivery' => 'pickup', 'city' => 'Київ', 'np_office' => 'Відділення №5', 'address' => null];

        $this->ok('Нова Пошта: місто й відділення',
            OrderFlow::deliveryAddress($np) === 'Київ, Відділення №5');
        $this->ok('інша доставка: місто й вулиця',
            OrderFlow::deliveryAddress($other) === 'Ніжин, вул. Шевченка, 1');
        $this->ok('самовивіз адреси не має', OrderFlow::deliveryAddress($pickup) === '');

        $vars = ['number' => 'BOFU-1', 'name' => 'Марія', 'phone' => '+380500000000',
                 'delivery' => 'Нова Пошта', 'address' => OrderFlow::deliveryAddress($np),
                 'items' => "• Мед липовий, 0.5 л × 2\n• Прополіс × 1",
                 'total' => '600.00', 'store' => 'Магазин №1'];
        $text = Notify::interpolate(Notify::DEFAULT_TEMPLATES['order_new'], $vars);
        $this->ok('адреса в повідомленні є', str_contains($text, 'Київ, Відділення №5'));
        $this->ok('видно, що замовили', str_contains($text, '• Мед липовий, 0.5 л × 2'));
        $this->ok('телефон окремим рядком', str_contains($text, "\n+380500000000"));
        $this->ok('телефон стоїть перед імʼям',
            strpos($text, '+380500000000') < strpos($text, 'Клієнт: Марія'));
        $this->ok('порожніх рядків немає', !str_contains($text, "\n\n"));

        $text = Notify::interpolate(Notify::DEFAULT_TEMPLATES['order_new'],
            ['address' => '', 'items' => ''] + $vars);
        $this->ok('без адреси й позицій рядки зникають, а не порожніють',
            !str_contains($text, "\n\n"));
        $this->ok('решта полів на місці', str_contains($text, 'Доставка: Нова Пошта')
            && str_contains($text, 'Клієнт: Марія'));

        $this->testItemsSummary();
    }

    /** Список позицій: варіант у назві, кількість завжди, довге замовлення обрізане */
    private function testItemsSummary(): void
    {
        $orderId = DB::insert('orders', [
            'number' => 'BOFU-TEST-' . bin2hex(random_bytes(3)), 'name' => 'Тест', 'phone' => '+380500000001',
            'delivery' => 'np', 'total' => 0, 'created_at' => now(),
        ]);
        try {
            DB::insert('order_items', ['order_id' => $orderId, 'title' => 'Мед липовий',
                'variant_name' => '0.5 л', 'price' => 300, 'qty' => 2, 'sum' => 600]);
            DB::insert('order_items', ['order_id' => $orderId, 'title' => 'Прополіс',
                'variant_name' => null, 'price' => 120, 'qty' => 1, 'sum' => 120]);
            $this->ok('варіант у назві, кількість у кожного рядка',
                OrderFlow::itemsSummary($orderId) === "• Мед липовий, 0.5 л × 2\n• Прополіс × 1");

            for ($i = 0; $i < 3; $i++) {
                DB::insert('order_items', ['order_id' => $orderId, 'title' => 'Свічка ' . $i,
                    'variant_name' => null, 'price' => 50, 'qty' => 1, 'sum' => 50]);
            }
            $short = OrderFlow::itemsSummary($orderId, 2);
            $this->ok('довге замовлення обрізане', substr_count($short, "\n") === 2);
            $this->ok('і сказано, скільки лишилось', str_contains($short, '…та ще 3 поз.'));
            $this->ok('порожнє замовлення дає порожній рядок', OrderFlow::itemsSummary(0) === '');
        } finally {
            DB::delete('order_items', 'order_id = ?', [$orderId]);
            DB::delete('orders', 'id = ?', [$orderId]);
        }
    }
}

return (new NotifyTest())->run();
