<?php
/**
 * Вхід через месенджер: як номер телефону перетворюється на акаунт.
 * Запуск: php bin/cli.php test
 *
 * Найважливіше тут — злиття за номером. Покупець міг замовляти без реєстрації,
 * потім увійти через Telegram; якщо шукати спершу за chat_id, він отримає другий
 * акаунт, а замовлення лишаться в першому. Тому порядок пошуку «спершу телефон»
 * — це не деталь реалізації, а вимога, і вона тут зафіксована.
 *
 * Тест створює власні записи й прибирає їх за собою.
 */
declare(strict_types=1);

final class BotAuthTest
{
    private int $pass = 0;
    private int $fail = 0;
    private array $made = [];
    private array $savedSettings = [];

    public function run(): int
    {
        try {
            $this->testMergeByPhone();
            $this->testMovesLinkFromDuplicate();
            $this->testFindByMessengerId();
            $this->testCreatesNew();
            $this->testInactiveGetsNothing();
            $this->testLinkedUser();
            $this->testLoginFrom();
            $this->testSiteUrl();
            $this->testTexts();
        } finally {
            $this->tearDown();
        }
        echo "\n" . ($this->fail === 0
            ? "УСЕ ДОБРЕ: {$this->pass} перевірок\n"
            : "ПРОВАЛЕНО: {$this->fail} з " . ($this->pass + $this->fail) . "\n");
        return $this->fail === 0 ? 0 : 1;
    }

    private function ok(string $what, bool $cond): void
    {
        if ($cond) { $this->pass++; echo "  ok   $what\n"; }
        else { $this->fail++; echo "  FAIL $what\n"; }
    }

    private function group(string $name): void { echo "\n== $name ==\n"; }

    private function mkUser(array $data): int
    {
        $id = DB::insert('users', array_merge([
            'email' => 'botauth-' . bin2hex(random_bytes(4)) . '@bofu.test',
            'name' => 'Тест', 'active' => 1, 'created_at' => now(),
        ], $data));
        $this->made[] = $id;
        return $id;
    }

    private function tearDown(): void
    {
        foreach ($this->made as $id) DB::delete('users', 'id = ?', [$id]);
        foreach ($this->savedSettings as $k => $v) Settings::set($k, (string)$v);
    }

    /** Замовляв за номером, потім прийшов у бота — це та сама людина */
    private function testMergeByPhone(): void
    {
        $this->group('номер склеює акаунти');
        $phone = '+380670000911';
        $old = $this->mkUser(['phone' => $phone, 'name' => 'Ганна Коваль']);

        $got = BotAuth::resolveUser('tg_chat_id', 'tg-911', $phone, 'Ганна з Telegram');
        $this->ok('увійшли в наявний акаунт, а не створили другий', $got === $old);

        $row = DB::row('SELECT * FROM users WHERE id = ?', [$old]);
        $this->ok('chat_id привʼязався до нього', (string)$row['tg_chat_id'] === 'tg-911');
        $this->ok('імʼя з профілю не затерте ніком з месенджера', $row['name'] === 'Ганна Коваль');
        $this->ok('другого акаунта не зʼявилось',
            (int)DB::val('SELECT COUNT(*) FROM users WHERE phone = ?', [$phone]) === 1);
    }

    /**
     * Саме той випадок, що ламав вхід: у людини є справжній акаунт (з ролями)
     * і покинутий дубль, створений ботом. Номер має привести в справжній,
     * а дубль — лишитись без прив'язки, інакше наступний вхід кине монетку.
     */
    private function testMovesLinkFromDuplicate(): void
    {
        $this->group('дубль віддає прив\'язку справжньому акаунту');
        $chat = 'tg-dup-1';
        $dup = $this->mkUser(['tg_chat_id' => $chat, 'name' => 'Дубль з бота', 'phone' => null]);
        $real = $this->mkUser(['phone' => '+380670000966', 'name' => 'Справжній акаунт']);
        DB::insert('user_roles', ['user_id' => $real, 'role' => Roles::ADMIN, 'created_at' => now()]);

        $got = BotAuth::resolveUser('tg_chat_id', $chat, '+380670000966', 'Нік у Telegram');
        $this->ok('увійшли у справжній акаунт, а не в дубль', $got === $real);
        $this->ok('прив\'язка переїхала до нього',
            DB::val('SELECT tg_chat_id FROM users WHERE id = ?', [$real]) === $chat);
        $this->ok('у дубля прив\'язки більше немає',
            DB::val('SELECT tg_chat_id FROM users WHERE id = ?', [$dup]) === null);
        $this->ok('chat_id лишився рівно в одного',
            (int)DB::val('SELECT COUNT(*) FROM users WHERE tg_chat_id = ?', [$chat]) === 1);
        $this->ok('ролі справжнього акаунта на місці — вхід дає адміна',
            (int)DB::val('SELECT COUNT(*) FROM user_roles WHERE user_id = ? AND role = ?', [$real, Roles::ADMIN]) === 1);

        DB::delete('user_roles', 'user_id = ?', [$real]);
    }

    /** Номер змінився (людина ввела інший), але месенджер той самий */
    private function testFindByMessengerId(): void
    {
        $this->group('пошук за id месенджера');
        $u = $this->mkUser(['viber_id' => 'vb-77', 'phone' => null]);
        $got = BotAuth::resolveUser('viber_id', 'vb-77', '+380670000922', 'Хтось');
        $this->ok('знайшли за viber_id', $got === $u);
        $this->ok('номер записався в акаунт',
            DB::val('SELECT phone FROM users WHERE id = ?', [$u]) === '+380670000922');
    }

    private function testCreatesNew(): void
    {
        $this->group('нової людини ще немає');
        $before = (int)DB::val('SELECT COUNT(*) FROM users');
        $id = BotAuth::resolveUser('tg_chat_id', 'tg-new-1', '+380670000933', 'Новий Покупець');
        $this->made[] = $id;
        $row = DB::row('SELECT * FROM users WHERE id = ?', [$id]);
        $this->ok('акаунт створено', $id > 0 && (int)DB::val('SELECT COUNT(*) FROM users') === $before + 1);
        $this->ok('одразу з номером — без нього гейт не пустив би далі профілю',
            $row['phone'] === '+380670000933');
        $this->ok('імʼя взяте з месенджера', $row['name'] === 'Новий Покупець');
        $this->ok('роль — покупець', $row['role'] === 'customer');
    }

    /** Вимкнений акаунт не має входити навіть із правильним номером */
    private function testInactiveGetsNothing(): void
    {
        $this->group('вимкнений акаунт');
        $phone = '+380670000944';
        $this->mkUser(['phone' => $phone, 'active' => 0]);
        $this->ok('входу не дано', BotAuth::resolveUser('tg_chat_id', 'tg-944', $phone, 'Хтось') === 0);
    }

    /**
     * Привʼязаний месенджер пускає без контакту — на цьому тримається повторний
     * вхід. Вимкнений акаунт мусить лишитись за бортом і тут теж, інакше
     * «вимкнено» перестало б щось означати.
     */
    private function testLinkedUser(): void
    {
        $this->group('привʼязаний месенджер пускає без контакту');
        $u = $this->mkUser(['tg_chat_id' => 'tg-linked-1', 'phone' => '+380670000977']);
        $off = $this->mkUser(['viber_id' => 'vb-off-1', 'phone' => '+380670000988', 'active' => 0]);

        $found = BotAuth::linkedUser('tg_chat_id', 'tg-linked-1');
        $this->ok('знайшли акаунт за привʼязкою', (int)($found['id'] ?? 0) === $u);
        $this->ok('незнайомий chat_id нічого не дає', BotAuth::linkedUser('tg_chat_id', 'tg-nobody') === null);
        $this->ok('вимкнений акаунт не пускає', BotAuth::linkedUser('viber_id', 'vb-off-1') === null);
        $this->ok('чуже поле не приймається', (function () {
            try { BotAuth::linkedUser('email', 'x'); return false; }
            catch (InvalidArgumentException $e) { return true; }
        })());
        unset($off);
    }

    /**
     * «Звідки вхід» у підтвердженні. Головне тут — Edge і Opera не мають
     * називатись Chrome, а Chrome — Safari: саме за цим рядком людина вирішує,
     * її це спроба чи чужа.
     */
    private function testLoginFrom(): void
    {
        $this->group('звідки почався вхід');
        $chrome = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';
        $edge = $chrome . ' Edg/120.0';
        $iphone = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1 Version/17.0 Mobile/15E148 Safari/604.1';

        $this->ok('Chrome на Windows', AuthTokens::device($chrome) === 'Chrome на Windows');
        $this->ok('Edge не видає себе за Chrome', AuthTokens::device($edge) === 'Edge на Windows');
        $this->ok('Safari на iPhone', AuthTokens::device($iphone) === 'Safari на iPhone');
        $this->ok('порожній User-Agent нічого не вигадує', AuthTokens::device('') === null);

        $vars = BotAuth::loginFrom(['agent' => 'Firefox на Linux', 'ip' => '203.0.113.7']);
        $this->ok('підстановки беруться з токена',
            $vars === ['device' => 'Firefox на Linux', 'ip' => '203.0.113.7']);
        $this->ok('невідоме показуємо як невідоме',
            BotAuth::loginFrom([]) === ['device' => 'невідомий пристрій', 'ip' => '—']);

        $text = BotAuth::text('bot_confirm_login', $vars + ['messenger' => 'Telegram']);
        $this->ok('у тексті підтвердження видно пристрій і IP',
            str_contains($text, 'Firefox на Linux') && str_contains($text, '203.0.113.7'));
        $this->ok('незаповнених плейсхолдерів не лишилось', !str_contains($text, '{'));
    }

    /**
     * Кнопка-посилання на сайт: локальна адреса Telegram-ом відхиляється
     * («Wrong HTTP URL») разом з усім повідомленням, тож такої кнопки не має
     * бути взагалі.
     */
    private function testSiteUrl(): void
    {
        $this->group('адреса сайту для кнопки в боті');
        $this->savedSettings['bot_site_url'] = (string)Settings::get('bot_site_url', '');
        Settings::set('bot_site_url', '');
        $host = $_SERVER['HTTP_HOST'] ?? null;

        $_SERVER['HTTP_HOST'] = 'localhost';
        $this->ok('localhost кнопки не дає', BotAuth::siteUrl() === '');
        $_SERVER['HTTP_HOST'] = '127.0.0.1:8080';
        $this->ok('петля теж ні', BotAuth::siteUrl() === '');
        $_SERVER['HTTP_HOST'] = 'shop.example.com';
        $this->ok('справжній домен дає адресу', str_starts_with(BotAuth::siteUrl(), 'http://shop.example.com'));

        Settings::set('bot_site_url', 'https://bofu.com.ua/');
        $_SERVER['HTTP_HOST'] = 'localhost';
        $this->ok('поле в налаштуваннях головніше за автовизначення',
            BotAuth::siteUrl() === 'https://bofu.com.ua');

        if ($host === null) unset($_SERVER['HTTP_HOST']); else $_SERVER['HTTP_HOST'] = $host;
    }

    private function testTexts(): void
    {
        $this->group('тексти бота');
        $key = 'bot_done';
        $this->savedSettings[$key] = (string)Settings::get($key, '');

        Settings::set($key, '');
        $this->ok('порожнє налаштування = типовий текст',
            BotAuth::text($key) === str_replace(
                ['{name}', '{site_name}'], ['', (string)cfg('app_name')], BotAuth::TEXTS[$key][0]));

        Settings::set($key, 'Вітаю, {name}! Ваш номер {phone}. Сайт: {site_name}');
        $this->ok('підстановки працюють',
            BotAuth::text($key, ['name' => 'Оля', 'phone' => '+380670000955'])
            === 'Вітаю, Оля! Ваш номер +380670000955. Сайт: ' . cfg('app_name'));

        Settings::set($key, 'Текст без підстановок');
        $this->ok('текст без плейсхолдерів лишається собою',
            BotAuth::text($key, ['name' => 'Оля']) === 'Текст без підстановок');
    }
}

return (new BotAuthTest())->run();
