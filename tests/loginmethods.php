<?php
/**
 * Способи входу в акаунт: що налаштоване, що дозволене, що пускає.
 * Запуск: php bin/cli.php test
 *
 * Пароля в системі немає: увійти можна через Google, Telegram, кодом на
 * телефон або кодом на пошту. Кожен спосіб рівносильний решті — хто пройшов
 * будь-яким, той усередині. Тому «вимкнути зайвий спосіб» тут не косметика в
 * інтерфейсі, а на один шлях усередину менше, і саме це набір і перевіряє.
 *
 * Три речі, які тут найважливіші й найлегше зламати наступною правкою:
 *
 *   1. Ненастроєний спосіб не можна ані обрати, ані використати. Дозвіл на те,
 *      чого в акаунті немає, — це рядок, який тихо чекає, доки месенджер
 *      підключать.
 *   2. Не можна лишитись зовсім без входу. Форма, якою людина замикає себе
 *      назовні, гірша за відсутність форми.
 *   3. Заборона діє на сервері. Сховати кнопку — не заборона.
 *
 * Тест створює власних користувачів і прибирає їх за собою.
 */
declare(strict_types=1);

final class LoginMethodsTest
{
    private int $pass = 0;
    private int $fail = 0;
    private array $made = [];
    private array $savedSettings = [];

    public function run(): int
    {
        try {
            $this->setUp();
            $this->testReadinessFollowsAccount();
            $this->testDefaultAllowsEverythingConfigured();
            $this->testSaveKeepsOnlyConfigured();
            $this->testCannotLockYourselfOut();
            $this->testDisabledMethodIsRefused();
            $this->testCodeChannelPrefersTelegram();
            $this->testFallbackWhenNothingWorks();
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

    /**
     * Інтеграції мають бути «налаштованими», інакше готовим не буде жоден
     * спосіб і перевіряти стане нічого. Значення несправжні: жодного виклику
     * назовні набір не робить.
     */
    private function setUp(): void
    {
        foreach (['telegram_bot_token', 'viber_bot_token', 'google_client_id',
                  'google_client_secret', 'notify_email_enabled'] as $k) {
            $this->savedSettings[$k] = Settings::get($k, null);
        }
        Settings::set('telegram_bot_token', '000000:TEST');
        Settings::set('viber_bot_token', 'test-viber-token');
        Settings::set('google_client_id', 'test.apps.googleusercontent.com');
        Settings::set('google_client_secret', 'test-secret');
        Settings::set('notify_email_enabled', '1');
    }

    private function tearDown(): void
    {
        foreach ($this->made as $id) {
            DB::delete('user_login_methods', 'user_id = ?', [$id]);
            DB::delete('auth_log', 'user_id = ?', [$id]);
            DB::delete('users', 'id = ?', [$id]);
        }
        foreach ($this->savedSettings as $k => $v) {
            if ($v === null) DB::delete('settings', '`key` = ?', [$k]);
            else Settings::set($k, (string)$v);
        }
    }

    /** Користувач із заданим набором підключень */
    private function user(array $fields = []): array
    {
        $id = DB::insert('users', array_merge([
            'email' => 'lm-' . bin2hex(random_bytes(5)) . '@example.com',
            'name' => 'Тест входів', 'role' => 'customer', 'active' => 1,
            'phone' => '+38067' . random_int(1000000, 9999999),
            'created_at' => now(),
        ], $fields));
        $this->made[] = $id;
        return DB::row('SELECT * FROM users WHERE id = ?', [$id]);
    }

    /**
     * Готовність рахується з акаунта, а не з бажання.
     *
     * Найчастіша майбутня помилка — вважати спосіб доступним, бо інтеграція
     * увімкнена на сайті. Сайт може мати бота, а конкретна людина його не
     * підключати, і код нікуди буде слати.
     */
    private function testReadinessFollowsAccount(): void
    {
        $this->group('що вважається налаштованим');

        $bare = $this->user(['email' => 'nobody-' . bin2hex(random_bytes(4)) . '@example.com']);
        $st = LoginMethods::forUser($bare);
        $this->ok('без Google акаунт не має входу через Google', !$st['google']['ready']);
        $this->ok('без Telegram немає входу через Telegram', !$st['telegram']['ready']);
        $this->ok('без месенджера немає коду на телефон', !$st['phone']['ready']);
        $this->ok('справжня пошта дає вхід за кодом', $st['email']['ready']);
        $this->ok('для недоступного способу є пояснення', $st['google']['hint'] !== '');

        // Технічна адреса — не скринька: лист туди не піде, і вхід нею
        // відкривав би акаунти, заведені продавцем на касі
        $offline = $this->user(['email' => 'abc123@offline.local']);
        $this->ok('технічна адреса .local входу не дає',
            !LoginMethods::forUser($offline)['email']['ready']);

        $tg = $this->user(['tg_chat_id' => '12345678', 'phone_verified_at' => now()]);
        $stTg = LoginMethods::forUser($tg);
        $this->ok('підключений Telegram дає свій спосіб', $stTg['telegram']['ready']);
        $this->ok('і заразом код на телефон', $stTg['phone']['ready']);

        // Месенджер каже лише «є куди надіслати», а не «номер його». Вписати в
        // профіль можна чужий номер, і без цієї умови його власник, входячи
        // своїм номером, слав би код у чужий месенджер.
        $unverified = $this->user(['tg_chat_id' => '87654321']);
        $stUnv = LoginMethods::forUser($unverified);
        $this->ok('сам Telegram входу за номером ще не відкриває', !$stUnv['phone']['ready']);
        $this->ok('а вхід через сам Telegram лишається', $stUnv['telegram']['ready']);
    }

    /** Поки вибору не робили — працює все, що налаштоване */
    private function testDefaultAllowsEverythingConfigured(): void
    {
        $this->group('за замовчуванням');

        $u = $this->user(['tg_chat_id' => '2222', 'phone_verified_at' => now()]);
        $this->ok('у базі немає жодного рядка вибору', LoginMethods::allowed((int)$u['id']) === []);
        $this->ok('Telegram пускає', LoginMethods::permits($u, 'telegram'));
        $this->ok('код на телефон пускає', LoginMethods::permits($u, 'phone'));
        $this->ok('пошта пускає', LoginMethods::permits($u, 'email'));
        $this->ok('Google не пускає — його в акаунті немає', !LoginMethods::permits($u, 'google'));
        $this->ok('вигаданий спосіб не пускає', !LoginMethods::permits($u, 'sms'));
    }

    /**
     * Формою не записати собі дозвіл на те, чого немає.
     *
     * Це і є друга половина вимоги «якщо метод не налаштований — не дозволяти»:
     * перша ховає галку, друга не вірить надісланому.
     */
    private function testSaveKeepsOnlyConfigured(): void
    {
        $this->group('зберігаємо лише налаштоване');

        $u = $this->user(['tg_chat_id' => '3333']);
        $err = LoginMethods::save($u, ['telegram', 'google', 'email', 'нісенітниця']);
        $this->ok('збереження пройшло', $err === '');

        $saved = LoginMethods::allowed((int)$u['id']);
        sort($saved);
        $this->ok('Google не записався — акаунт не повʼязаний із ним', !in_array('google', $saved, true));
        $this->ok('вигадане значення не записалось', !in_array('нісенітниця', $saved, true));
        $this->ok('лишились саме налаштовані', $saved === ['email', 'telegram']);

        $this->ok('подія потрапила в журнал',
            (bool)DB::val("SELECT 1 FROM auth_log WHERE user_id = ? AND event = 'login_methods_changed'",
                [(int)$u['id']]));
    }

    /** Замкнути себе назовні форма не дає */
    private function testCannotLockYourselfOut(): void
    {
        $this->group('без входу лишитись не можна');

        $u = $this->user(['tg_chat_id' => '4444']);
        $before = LoginMethods::allowed((int)$u['id']);

        $err = LoginMethods::save($u, []);
        $this->ok('порожній вибір відхилено', $err !== '');
        $this->ok('людині пояснено чому', str_contains($err, 'хоча б один'));
        $this->ok('збережене не змінилось', LoginMethods::allowed((int)$u['id']) === $before);

        // Те саме, але хитріше: обрано лише те, чого в акаунті немає
        $err2 = LoginMethods::save($u, ['google']);
        $this->ok('вибір з одних лише недоступних теж відхилено', $err2 !== '');
        $this->ok('і теж нічого не зламав', LoginMethods::allowed((int)$u['id']) === $before);
    }

    /** Вимкнений спосіб справді не пускає */
    private function testDisabledMethodIsRefused(): void
    {
        $this->group('вимкнений спосіб не пускає');

        $u = $this->user(['tg_chat_id' => '5555']);
        LoginMethods::save($u, ['telegram']);

        $this->ok('дозволений спосіб працює', LoginMethods::permits($u, 'telegram'));
        $this->ok('пошта більше не пускає', !LoginMethods::permits($u, 'email'));
        $this->ok('код на телефон більше не пускає', !LoginMethods::permits($u, 'phone'));
        $this->ok('у відмові названо спосіб', str_contains(LoginMethods::denial('email'), 'пошту'));
        $this->ok('і сказано, де це змінити', str_contains(LoginMethods::denial('email'), 'профіл'));

        // Профіль показує вимкнене саме вимкненим, а не зниклим
        $st = LoginMethods::forUser($u);
        $this->ok('вимкнений спосіб лишається видимим', isset($st['email']));
        $this->ok('і показаний як вимкнений', $st['email']['ready'] && !$st['email']['on']);
    }

    /**
     * Куди піде код. Telegram перший навмисно: доставка миттєва й не залежить
     * від того, чи відкритий застосунок.
     */
    private function testCodeChannelPrefersTelegram(): void
    {
        $this->group('куди йде код');

        $both = $this->user(['tg_chat_id' => '6666', 'viber_id' => 'vb-6666']);
        $ch = LoginMethods::codeChannel($both);
        $this->ok('з двох каналів обрано Telegram', ($ch['channel'] ?? '') === 'telegram');
        $this->ok('і названо його людині', ($ch['label'] ?? '') === 'Telegram');
        $this->ok('адресат — саме підтверджений чат', ($ch['to'] ?? '') === '6666');

        $viberOnly = $this->user(['viber_id' => 'vb-7777', 'phone_verified_at' => now()]);
        $ch2 = LoginMethods::codeChannel($viberOnly);
        $this->ok('лише Viber — код піде у Viber', ($ch2['channel'] ?? '') === 'viber');
        $this->ok('Viber теж дає код на телефон', LoginMethods::permits($viberOnly, 'phone'));

        $none = $this->user();
        $this->ok('без месенджерів слати нікуди', LoginMethods::codeChannel($none) === null);

        // Вимкнений на сайті месенджер — це теж «нікуди»
        Settings::set('telegram_bot_token', '');
        Settings::set('viber_bot_token', '');
        $this->ok('вимкнені на сайті боти прибирають канал',
            LoginMethods::codeChannel($both) === null);
        Settings::set('telegram_bot_token', '000000:TEST');
        Settings::set('viber_bot_token', 'test-viber-token');
    }

    /**
     * Запобіжник від мертвого акаунта.
     *
     * Якщо єдиний дозволений спосіб перестав працювати не з волі людини (адмін
     * вимкнув інтеграцію на сайті), акаунт не має перетворюватись на закриті
     * двері без ключа. Дірки тут немає: власними діями цього не досягти —
     * відключити месенджер можна лише зсередини акаунта.
     */
    private function testFallbackWhenNothingWorks(): void
    {
        $this->group('дозволений спосіб зник сам');

        $u = $this->user(['tg_chat_id' => '8888']);
        LoginMethods::save($u, ['telegram']);
        $this->ok('поки Telegram працює — пошта закрита', !LoginMethods::permits($u, 'email'));

        Settings::set('telegram_bot_token', '');   // адмін вимкнув бота на сайті
        $this->ok('Telegram більше не працює', !LoginMethods::permits($u, 'telegram'));
        $this->ok('пошта відчинилась, щоб акаунт не помер', LoginMethods::permits($u, 'email'));
        $this->ok('і про це є запис у журналі',
            (bool)DB::val("SELECT 1 FROM auth_log WHERE user_id = ? AND event = 'login_fallback'",
                [(int)$u['id']]));

        Settings::set('telegram_bot_token', '000000:TEST');
        $this->ok('бот повернувся — заборона знову діє', !LoginMethods::permits($u, 'email'));
    }
}

return (new LoginMethodsTest())->run();
