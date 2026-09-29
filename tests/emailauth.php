<?php
/**
 * Вхід поштою за одноразовим кодом і те, чим доведено номер телефону.
 * Запуск: php bin/cli.php test
 *
 * Тут перевіряється не «чи прийшов лист» — пошту ми не контролюємо, — а два
 * правила, ціна помилки в яких висока:
 *
 *   1) Кодом входить лише той, у кого справді є ця скринька. Тому: чужий код
 *      не пускає, використаний код не спрацьовує двічі, а технічні адреси
 *      акаунтів (@offline.local, @telegram.local) не приймаються взагалі —
 *      інакше запис, який продавець завів на покупця, ставав би доступним
 *      кожному, хто вгадає його вигадану адресу.
 *
 *   2) Вписаний руками номер НЕ робить акаунт власником номера. Саме на цьому
 *      трималась дірка, через яку прибрали вхід через Viber: замовлення, які
 *      продавець оформлює по телефону, знаходять покупця за номером, і
 *      «вписав чужий номер» означало б «бачу чужі покупки».
 *
 * Набір створює власні записи й прибирає їх за собою.
 */
declare(strict_types=1);

final class EmailAuthTest
{
    private int $pass = 0;
    private int $fail = 0;
    private array $users = [];
    private array $orders = [];
    private array $emails = [];

    public function run(): int
    {
        /*
         * Пошту підміняємо на весь набір. Інакше перевірки залежали б від того,
         * чи налаштований mail() на машині, де їх запускають: у розробника він
         * не працює завжди, на сервері працює — і той самий набір давав би різні
         * результати. Заразом жоден справжній лист нікуди не піде.
         */
        Notify::useMailer(fn(string $to, string $s, string $t): bool => true);
        try {
            $this->testNormEmail();
            $this->testSendCode();
            $this->testSendFailure();
            $this->testResendRules();
            $this->testRateLimitPerAddress();
            $this->testWrongCode();
            $this->testCreatesAccount();
            $this->testSecondLoginSameAccount();
            $this->testCodeIsSingleUse();
            $this->testInactiveAccount();
            $this->testOwnsPhone();
            $this->testResolveRefusesUnprovenPhone();
            $this->testClaimOrders();
            $this->testMaskEmail();
        } finally {
            Notify::useMailer(null);
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

    /** Своя адреса на кожен виклик: інакше стеля «три коди на годину» з'їла б набір */
    private function mail(): string
    {
        $e = 'mailauth-' . bin2hex(random_bytes(5)) . '@bofu.test';
        $this->emails[] = $e;
        return $e;
    }

    private function mkUser(array $data): int
    {
        $id = DB::insert('users', array_merge([
            'email' => $this->mail(), 'name' => 'Тест', 'active' => 1, 'created_at' => now(),
        ], $data));
        $this->users[] = $id;
        return $id;
    }

    private function mkOrder(?int $userId, string $phone): int
    {
        $id = DB::insert('orders', [
            'number' => 'TEST-' . bin2hex(random_bytes(4)), 'token' => bin2hex(random_bytes(8)),
            'user_id' => $userId, 'name' => 'Тест', 'phone' => $phone,
            'delivery' => 'pickup', 'status' => 'new', 'total' => 0, 'created_at' => now(),
        ]);
        $this->orders[] = $id;
        return $id;
    }

    /**
     * Відмотати паузу між кодами.
     *
     * Між двома листами на одну адресу є хвилина (AuthTokens::RESEND_SEC), і це
     * правильно — але набір тестів не має її висиджувати. Робимо створений код
     * «старішим» рівно так, як це зробив би час, і йдемо далі. Там, де
     * перевіряється сама пауза, цей помічник, звісно, не викликається.
     */
    private function agePendingCode(string $email): void
    {
        DB::query("UPDATE auth_tokens SET created_at = ? WHERE purpose = 'email_code' AND email = ?",
            [date('Y-m-d H:i:s', time() - AuthTokens::RESEND_SEC - 5), $email]);
    }

    /** Код із бази: у тесті ми на місці листоноші, який має право зазирнути в лист */
    private function codeOf(string $token): string
    {
        return (string)DB::val("SELECT code FROM auth_tokens WHERE token = ?", [$token]);
    }

    /**
     * Прибираємо за адресами, а не лише за зібраними id.
     *
     * Половину акаунтів тут створює не тест, а сам код під перевіркою:
     * EmailAuth::verify() на невідому адресу заводить користувача — у цьому й
     * полягає перевірка. У $this->users такий id не потрапляє, і кожен прогін
     * лишав по кілька рядків у базі назавжди. Адреса ж відома завжди: усі вони
     * видані self::mail() і закінчуються на @bofu.test.
     */
    private function tearDown(): void
    {
        foreach ($this->orders as $id) DB::delete('orders', 'id = ?', [$id]);
        foreach ($this->emails as $e) {
            DB::delete('auth_tokens', 'email = ?', [$e]);
            $uid = DB::val('SELECT id FROM users WHERE email = ?', [$e]);
            if ($uid) $this->users[] = (int)$uid;
        }
        foreach (array_unique($this->users) as $id) {
            DB::delete('course_access', 'user_id = ?', [$id]);
            DB::delete('user_roles', 'user_id = ?', [$id]);
            DB::delete('users', 'id = ?', [$id]);
        }
    }

    private function testNormEmail(): void
    {
        $this->group('яка адреса взагалі годиться');
        $this->ok('звичайна пошта приймається', EmailAuth::normEmail(' Ivan@Ukr.NET ') === 'ivan@ukr.net');
        $this->ok('сміття не приймається', EmailAuth::normEmail('ivan(at)ukr.net') === null);
        // Технічні адреси — не скриньки. Якби вони проходили, вхід у чужий
        // акаунт зводився б до вгадування рядка з md5 замість володіння поштою.
        $this->ok('@offline.local (покупець від продавця) не приймається',
            EmailAuth::normEmail('a1b2c3d4e5f6@offline.local') === null);
        $this->ok('@telegram.local не приймається', EmailAuth::normEmail('a1b2c3d4e5f6@telegram.local') === null);
    }

    private function testSendCode(): void
    {
        $this->group('код створюється й прив\'язується до адреси');
        $email = $this->mail();
        $res = EmailAuth::sendCode($email);
        $this->ok('код надіслано', !empty($res['ok']) && !empty($res['token']));
        $row = DB::row("SELECT * FROM auth_tokens WHERE token = ?", [$res['token']]);
        $this->ok('токен лежить із призначенням email_code', $row && $row['purpose'] === 'email_code');
        $this->ok('адреса збережена при токені', $row && $row['email'] === $email);
        $this->ok('код шестизначний', $row && preg_match('/^\d{6}$/', (string)$row['code']) === 1);

        $bad = EmailAuth::sendCode('не пошта');
        $this->ok('некоректна адреса — відмова з поясненням', empty($bad['ok']) && !empty($bad['error']));
    }

    /**
     * Лист не пішов — форма мусить це сказати.
     *
     * Досі sendCode() повертав ok навіть тоді, коли mail() відповів false:
     * покупець бачив «Код надіслано. Введіть його нижче» й чекав листа, якого
     * ніхто не відправляв. На хостингу без робочої пошти це означало, що вхід
     * поштою мовчки не працює взагалі — і не видно, чому.
     */
    private function testSendFailure(): void
    {
        $this->group('лист не пішов — про це кажуть');
        $email = $this->mail();

        /*
         * Перевіряємо поведінку БОЙОВОГО режиму, тож debug на час перевірки
         * гасимо. У розробника він увімкнений (config.local.php), і там невдача
         * вхід навмисно не спиняє — пошти на машині немає ніколи, а код лежить
         * у storage/logs/php-error.log. Без цієї підміни той самий набір давав
         * би різні результати в розробника й на сервері.
         */
        $debugWas = $GLOBALS['bofu_config']['debug'] ?? false;
        $GLOBALS['bofu_config']['debug'] = false;

        Notify::useMailer(fn(string $to, string $s, string $t): bool => false);
        $res = EmailAuth::sendCode($email);
        Notify::useMailer(fn(string $to, string $s, string $t): bool => true);

        $this->ok('відповідь — відмова, а не «надіслано»', empty($res['ok']));
        $this->ok('і з поясненням для людини', !empty($res['error']));
        $this->ok('токена назовні не віддали', empty($res['token']));

        // Код, якого ніхто не отримав, не має лежати живим ще чверть години:
        // інакше він лишається робочим ключем, який висить у базі просто так
        $row = DB::row("SELECT * FROM auth_tokens WHERE purpose = 'email_code' AND email = ? ORDER BY id DESC LIMIT 1", [$email]);
        $this->ok('створений код одразу погашено', $row && (int)$row['used'] === 1);

        // Відмова стосується нашої пошти, а не адреси, тож про існування
        // акаунта вона не розповідає нічого — перевіряємо на знайомій адресі
        $known = $this->mail();
        $this->mkUser(['email' => $known]);
        Notify::useMailer(fn(string $to, string $s, string $t): bool => false);
        $res2 = EmailAuth::sendCode($known);
        Notify::useMailer(fn(string $to, string $s, string $t): bool => true);
        $this->ok('для знайомої адреси відповідь така сама',
            empty($res2['ok']) && ($res2['error'] ?? '') === ($res['error'] ?? '!'));

        // А тепер те, заради чого зроблено виняток: у режимі розробки та сама
        // невдача вхід не спиняє, інакше локально поштою не увійти взагалі
        $GLOBALS['bofu_config']['debug'] = true;
        Notify::useMailer(fn(string $to, string $s, string $t): bool => false);
        $dev = EmailAuth::sendCode($this->mail());
        Notify::useMailer(fn(string $to, string $s, string $t): bool => true);
        $this->ok('у режимі розробки вхід продовжується попри невдачу',
            !empty($dev['ok']) && !empty($dev['token']));

        $GLOBALS['bofu_config']['debug'] = $debugWas;
    }

    /**
     * Повторне надсилання: пауза між листами й смерть попереднього коду.
     *
     * Кнопка «ще раз» без паузи — це кнопка «завалити чужу скриньку»: адресу
     * вводить хто завгодно, а приходить воно власнику. А без погашення старого
     * коду три натискання дають три робочі ключі до акаунта одночасно, кожен зі
     * своїм строком життя.
     */
    private function testResendRules(): void
    {
        $this->group('повторне надсилання');
        $email = $this->mail();

        $first = EmailAuth::sendCode($email);
        $this->ok('перший код пішов', !empty($first['ok']));

        $tooSoon = EmailAuth::sendCode($email);
        $this->ok('одразу другий не дають', empty($tooSoon['ok']));
        $this->ok('і кажуть, скільки чекати',
            !empty($tooSoon['retry_after']) && $tooSoon['retry_after'] <= AuthTokens::RESEND_SEC);

        // Відмотуємо годинник: створений код «постарішав» на паузу. Так само,
        // як це зробить сам час, тільки без хвилини очікування в наборі тестів.
        DB::query("UPDATE auth_tokens SET created_at = ? WHERE purpose = 'email_code' AND email = ?",
            [date('Y-m-d H:i:s', time() - AuthTokens::RESEND_SEC - 5), $email]);

        $firstCode = $this->codeOf($first['token']);
        $second = EmailAuth::sendCode($email);
        $this->ok('після паузи новий код дають', !empty($second['ok']));
        $this->ok('це справді інший токен', ($second['token'] ?? '') !== ($first['token'] ?? ''));

        // Головне: старий ключ більше не відчиняє
        $old = EmailAuth::verify((string)$first['token'], $firstCode);
        $this->ok('попередній код більше не діє', empty($old['ok']));

        $new = EmailAuth::verify((string)$second['token'], $this->codeOf($second['token']));
        $this->ok('останній надісланий код працює', !empty($new['ok']));
    }

    private function testRateLimitPerAddress(): void
    {
        $this->group('стеля кодів на одну адресу');
        $email = $this->mail();
        // Пауза між листами тут не предмет перевірки — відмотуємо її, щоб
        // четвертий код упирався саме в годинну стелю, а не в хвилинну паузу
        for ($i = 0; $i < 3; $i++) { EmailAuth::sendCode($email); $this->agePendingCode($email); }
        $fourth = EmailAuth::sendCode($email);
        // Стеля саме на адресу, а не лише на IP: інакше зміна IP знімала б
        // обмеження з конкретної скриньки, і форма ставала б засобом її залити
        $this->ok('четвертий код за годину не надсилається', empty($fourth['ok']));
    }

    private function testWrongCode(): void
    {
        $this->group('чужий код не пускає');
        $email = $this->mail();
        $res = EmailAuth::sendCode($email);
        $real = $this->codeOf($res['token']);
        $wrong = $real === '111111' ? '222222' : '111111';

        $out = EmailAuth::verify($res['token'], $wrong);
        $this->ok('невірний код відхилено', empty($out['ok']));
        $this->ok('акаунт на цю адресу не з\'явився',
            (int)DB::val('SELECT COUNT(*) FROM users WHERE email = ?', [$email]) === 0);
        $this->ok('токен не витрачено — правильний код ще спрацює',
            (int)DB::val('SELECT used FROM auth_tokens WHERE token = ?', [$res['token']]) === 0);

        $out2 = EmailAuth::verify('токена-з-таким-рядком-немає', $real);
        $this->ok('вигаданий токен не пускає', empty($out2['ok']));
    }

    private function testCreatesAccount(): void
    {
        $this->group('перший вхід = реєстрація');
        $email = $this->mail();
        $res = EmailAuth::sendCode($email);
        $out = EmailAuth::verify($res['token'], $this->codeOf($res['token']));
        $this->ok('код прийнято', !empty($out['ok']) && !empty($out['user_id']));
        $uid = (int)$out['user_id'];
        $this->users[] = $uid;

        $u = DB::row('SELECT * FROM users WHERE id = ?', [$uid]);
        $this->ok('акаунт створено з цією поштою', $u && $u['email'] === $email);
        $this->ok('роль — покупець', $u && $u['role'] === Roles::CUSTOMER);
        $this->ok('пошта позначена підтвердженою', $u && !empty($u['email_verified_at']));
        // Головне обмеження цього входу: пошта не каже нічого про телефон
        $this->ok('номер не з\'явився і не вважається підтвердженим',
            $u && empty($u['phone']) && empty($u['phone_verified_at']));
    }

    private function testSecondLoginSameAccount(): void
    {
        $this->group('другий вхід — той самий акаунт');
        $email = $this->mail();
        $first = EmailAuth::sendCode($email);
        $a = EmailAuth::verify($first['token'], $this->codeOf($first['token']));
        $this->users[] = (int)$a['user_id'];

        $this->agePendingCode($email);
        $second = EmailAuth::sendCode($email);
        $b = EmailAuth::verify($second['token'], $this->codeOf($second['token']));
        $this->ok('id той самий', (int)$a['user_id'] === (int)$b['user_id']);
        $this->ok('другого акаунта на ту саму пошту не з\'явилось',
            (int)DB::val('SELECT COUNT(*) FROM users WHERE email = ?', [$email]) === 1);
    }

    private function testCodeIsSingleUse(): void
    {
        $this->group('код одноразовий');
        $email = $this->mail();
        $res = EmailAuth::sendCode($email);
        $code = $this->codeOf($res['token']);
        $ok = EmailAuth::verify($res['token'], $code);
        $this->users[] = (int)$ok['user_id'];
        $again = EmailAuth::verify($res['token'], $code);
        // Лист із кодом лишається в скриньці назавжди, і скриньку можуть
        // прочитати згодом — на чужому комп'ютері, зі старого телефону.
        // Тому код має вмирати першим використанням, а не строком.
        $this->ok('повторно той самий код не спрацьовує', empty($again['ok']));
    }

    private function testInactiveAccount(): void
    {
        $this->group('вимкнений акаунт');
        $email = $this->mail();
        $uid = $this->mkUser(['email' => $email, 'active' => 0]);
        $res = EmailAuth::sendCode($email);
        // Назовні відповідь звичайна — інакше форма стає способом перевіряти,
        // чи є така адреса в базі. Але коду не існує, і ввійти нічим.
        $this->ok('відповідь не видає, що акаунт вимкнено', !empty($res['ok']));
        $this->ok('коду при цьому не створено', empty($res['token'])
            && (int)DB::val("SELECT COUNT(*) FROM auth_tokens WHERE email = ?", [$email]) === 0);
        $this->ok('акаунт лишився вимкненим', (int)DB::val('SELECT active FROM users WHERE id = ?', [$uid]) === 0);
    }

    private function testOwnsPhone(): void
    {
        $this->group('хто вважається власником номера');

        $verified = DB::row('SELECT * FROM users WHERE id = ?',
            [$this->mkUser(['phone' => '+380670001101', 'phone_verified_at' => now(), 'tg_chat_id' => 'tg-own-1'])]);
        $this->ok('підтверджений номер — власник', Customers::ownsPhone($verified));

        // Запис продавця: технічна адреса й жодного каналу входу. Увійти в
        // нього не може ніхто, тож і привласнити його нема кому.
        $stub = DB::row('SELECT * FROM users WHERE id = ?',
            [$this->mkUser(['email' => bin2hex(random_bytes(6)) . '@offline.local', 'phone' => '+380670001102'])]);
        $this->ok('покупець, заведений продавцем, — власник', Customers::ownsPhone($stub));

        $typed = DB::row('SELECT * FROM users WHERE id = ?',
            [$this->mkUser(['phone' => '+380670001103', 'email_verified_at' => now()])]);
        $this->ok('вписаний руками номер (вхід поштою) — НЕ власник', !Customers::ownsPhone($typed));

        $google = DB::row('SELECT * FROM users WHERE id = ?',
            [$this->mkUser(['phone' => '+380670001104', 'google_id' => 'g-own-1', 'email_verified_at' => now()])]);
        $this->ok('вписаний руками номер (вхід Google) — НЕ власник', !Customers::ownsPhone($google));

        $tg = DB::row('SELECT * FROM users WHERE id = ?',
            [$this->mkUser(['phone' => '+380670001105', 'tg_chat_id' => 'tg-own-2'])]);
        $this->ok('Telegram без підтвердження номера — НЕ власник', !Customers::ownsPhone($tg));
    }

    private function testResolveRefusesUnprovenPhone(): void
    {
        $this->group('замовлення продавця не йде до непідтвердженого номера');

        $phone = '+380670001201';
        $impostor = $this->mkUser(['phone' => $phone, 'email_verified_at' => now(), 'name' => 'Хтось']);
        $got = Customers::resolve($phone, 'Покупець із трубки');
        $this->ok('покупця не причеплено — замовлення лишиться анонімним', $got === null);
        $this->ok('чужий акаунт не отримав нічого й не змінився',
            DB::val('SELECT name FROM users WHERE id = ?', [$impostor]) === 'Хтось');

        // Вільний номер працює як і працював: продавець заводить покупця
        $free = '+380670001202';
        $newId = Customers::resolve($free, 'Нова Покупчиня');
        $this->ok('на вільний номер акаунт створюється', is_int($newId) && $newId > 0);
        if ($newId) {
            $this->users[] = $newId;
            $row = DB::row('SELECT * FROM users WHERE id = ?', [$newId]);
            $this->ok('це запис продавця — власник свого номера', Customers::ownsPhone($row));
            $this->ok('повторний продаж іде в той самий запис', Customers::resolve($free, '') === $newId);
        }

        // Той, хто номер довів, отримує свої продажі
        $proven = '+380670001203';
        $owner = $this->mkUser(['phone' => $proven, 'phone_verified_at' => now(), 'tg_chat_id' => 'tg-own-3']);
        $this->ok('підтверджений номер отримує замовлення', Customers::resolve($proven, '') === $owner);
    }

    private function testClaimOrders(): void
    {
        $this->group('підтвердження номера забирає власні гостьові замовлення');
        $phone = '+380670001301';
        $mine = $this->mkOrder(null, $phone);                 // гість оформив без входу
        $someone = $this->mkOrder(null, '+380670001399');     // чуже гостьове
        $uid = $this->mkUser(['phone' => null]);
        $other = $this->mkUser(['phone' => null]);
        $taken = $this->mkOrder($other, $phone);              // той самий номер, але вже чиєсь

        $n = Customers::claimOrders($uid, $phone);
        $this->ok('причеплено рівно одне', $n === 1);
        $this->ok('гостьове замовлення на цей номер стало моїм',
            (int)DB::val('SELECT user_id FROM orders WHERE id = ?', [$mine]) === $uid);
        $this->ok('чуже гостьове не зачеплено',
            DB::val('SELECT user_id FROM orders WHERE id = ?', [$someone]) === null);
        // Найважливіше: те, що вже комусь належить, не перечіпляється НІКОЛИ —
        // інакше підтвердження номера ставало б способом відібрати замовлення
        $this->ok('замовлення, яке вже має власника, лишилось у нього',
            (int)DB::val('SELECT user_id FROM orders WHERE id = ?', [$taken]) === $other);
    }

    private function testMaskEmail(): void
    {
        $this->group('адреса в журналі замаскована');
        // Журнал невдалих спроб має показувати серію, а не перетворюватись на
        // список поштових адрес покупців
        $m = EmailAuth::maskEmail('ivanenko@ukr.net');
        $this->ok('домен видно, скриньку — ні', str_contains($m, '@ukr.net') && !str_contains($m, 'ivanenko'));
        $this->ok('коротка адреса теж прихована', !str_contains(EmailAuth::maskEmail('ai@i.ua'), 'ai@'));
    }
}

return (new EmailAuthTest())->run();
