<?php
declare(strict_types=1);

/**
 * Оплата карткою на сайті — інтернет-еквайринг Raiffeisen Bank.
 *
 * Банк не має власного протоколу: картковий трафік Райффайзена обслуговує
 * процесинг UPC (Ukrainian Processing Center, група RBI), а магазин говорить
 * із його шлюзом «eCommerce Connect». Тому все нижче — це UPC ECG, і саме
 * його документацію дасть банк разом із реквізитами. Самі реквізити
 * (MerchantID, TerminalID, ключ) видає банк-еквайєр, а не UPC.
 *
 * Карткових даних ми не бачимо взагалі й не хочемо бачити: покупець вводить їх
 * на сторінці шлюзу. Наша частина — підписаний запит туди й перевірений підпис
 * назад. Через це в коді немає жодного поля картки, і не має з’явитись:
 * щойно номер картки торкнеться нашого сервера, магазин потрапляє під PCI DSS
 * цілком, а не під найлегший його рівень.
 *
 * ГОЛОВНЕ ПРАВИЛО ФАЙЛУ: оплаченим замовлення робить лише перевірений підпис.
 * Повернення покупця в браузері нічого не доводить — параметрів там мінімум,
 * і ту саму адресу може відкрити руками будь-хто. Тому «оплачено» ставиться
 * тільки з NOTIFY-запиту шлюзу або з відповіді на наш власний запит статусу.
 *
 * Три речі вирішені навмисно й не мають переїжджати:
 *
 *   1. Одне замовлення — кілька спроб оплати. Шлюз відхиляє повтор із тим
 *      самим OrderID (код 412), а покупець, у якого не пройшла картка, майже
 *      завжди пробує другу. Тому платіж — окремий рядок із власним OrderID,
 *      а не пара стовпців у замовленні.
 *   2. Сума підписується в копійках і в тому ж вигляді повертається назад.
 *      Порівнюємо саме її, а не гривні: 1234.56 у float після двох перетворень
 *      уже не завжди дорівнює собі, а цілі копійки дорівнюють завжди.
 *   3. PurchaseTime зберігається рядком таким, яким пішов у підпис. Він бере
 *      участь у КОЖНОМУ подальшому запиті (статус, списання, повернення), і
 *      відтворити його потім із created_at не вийде — там інша точність.
 */
class Acquiring
{
    /**
     * Хто обслуговує еквайринг.
     *
     * Постачальника міняють: дорожчає тариф, псується підтримка, з'являється
     * зручніший. Тому він записується в САМ ПЛАТІЖ, а не читається з
     * налаштувань при потребі, — так само, як постачальник ПРРО в чеку
     * (див. FiscalProvider). Причина та сама й така сама груба: повернення
     * робить той, хто прийняв гроші. Платіж, проведений через Raiffeisen, не
     * повернеш через LiqPay, і після переходу старі платежі мусять лишатись
     * робочими, а не перетворюватись на «зверніться в банк».
     *
     * Щоб додати другого постачальника, потрібні три речі: рядок сюди, клас із
     * його протоколом і розгалуження за $payment['provider'] у sync/capture/
     * refund. Решта сайту — оформлення, картка замовлення, чеки, звірка — про
     * постачальника не знає нічого й змінюватись не має.
     */
    public const PROVIDER = 'raiffeisen';

    public const PROVIDERS = [
        'raiffeisen' => 'Raiffeisen Bank (шлюз UPC eCommerce Connect)',
    ];

    public static function providerLabel(?string $p): string
    {
        return self::PROVIDERS[(string)$p] ?? ('невідомий постачальник «' . (string)$p . '»');
    }

    public const ENVS = [
        'test' => 'Тестовий шлюз (гроші не рухаються)',
        'prod' => 'Робочий шлюз (справжні гроші)',
    ];

    /** Адреси шлюзу. Різні середовища — різні ключі й різні сертифікати. */
    public const BASE = [
        'test' => 'https://ecg.test.upc.ua',
        'prod' => 'https://secure.upc.ua',
    ];

    /**
     * Звідки шлюз стукає в NOTIFY_URL. Список із документації UPC.
     *
     * Використовуємо як підказку в журналі, а НЕ як замок: адреси змінюються
     * без нашого відома, і платіж, відкинутий через новий IP, виглядав би як
     * «гроші списались, замовлення не оплачене» — найгірша з можливих поломок.
     * Замком тут працює підпис.
     */
    public const NOTIFY_IPS = [
        'prod' => ['217.13.180.171'],
        'test' => ['18.196.61.127', '3.120.143.246', '18.197.170.36'],
    ];

    /** Успішна операція — рівно один код. */
    public const OK = '000';

    /**
     * Коди завершення (TranCode). Перша половина — відповідь банку-емітента,
     * друга — відмова самого шлюзу ще до звернення в банк.
     *
     * Тексти написані для покупця, а не для журналу: саме їх він побачить на
     * сторінці невдалої оплати. Тому «Недостатньо коштів», а не «116».
     */
    public const CODES = [
        '000' => 'Оплата пройшла',
        '101' => 'Невірні дані картки або сплив термін дії',
        '105' => 'Банк, що видав картку, не дозволив цю оплату',
        '108' => 'Картку заблоковано як загублену або вкрадену',
        '111' => 'Такої картки не існує — перевірте номер',
        '116' => 'Недостатньо коштів на картці',
        '130' => 'Перевищено ліміт за карткою',
        '131' => 'Банк вимагає додаткового підтвердження',
        '290' => 'Банк, що видав картку, тимчасово недоступний',
        '291' => 'Технічна проблема на боці банку',
        '701' => 'Банк, що видав картку, не дозволив цю оплату',
        '904' => 'Платіжна система відхилила запит',
        '401' => 'Помилка у даних запиту на оплату',
        '402' => 'Невірні реквізити магазину (Merchant ID / Terminal ID)',
        '404' => 'Не пройдено підтвердження 3D Secure',
        '405' => 'Шлюз не прийняв підпис магазину',
        '407' => 'Магазин заблоковано банком-еквайєром',
        '408' => 'Операцію не знайдено на шлюзі',
        '410' => 'Це замовлення вже оплачене',
        '411' => 'Термін дії платіжного посилання минув',
        '412' => 'Такий номер оплати вже використано',
        '414' => 'Оплата без CVV заборонена',
        '420' => 'Перевищено денний ліміт операцій',
        '421' => 'Перевищено максимальну суму операції',
        '430' => 'Операцію заборонено налаштуваннями еквайрингу',
        '454' => 'Передавання Ref3 не увімкнене для цього магазину',
        '455' => 'Повернення через API не увімкнене для цього магазину',
        '503' => 'Оплату скасовано магазином',
        '504' => 'Оплату скасовано шлюзом',
        '506' => 'Термін завершення блокування коштів минув',
        '507' => 'Блокування вже завершене',
        '508' => 'Невірна сума завершення блокування',
        '509' => 'Не знайдено операції блокування',
        '601' => 'Оплату не завершено — сторінку закрито',
        '902' => 'Не вдалося обробити операцію',
        '909' => 'Не вдалося обробити операцію',
    ];

    /**
     * Стани платежу.
     *
     * 'held' окремо від 'paid' не для краси: при преавторизації гроші на
     * картці ЗАБЛОКОВАНІ, але магазину ще не належать. Відвантажувати за таким
     * платежем можна, вважати виручкою — ні, доки не зроблено списання.
     */
    public const STATUSES = [
        'new'      => 'Створено',
        'sent'     => 'Покупця відправлено на оплату',
        'held'     => 'Кошти заблоковано',
        'paid'     => 'Оплачено',
        'failed'   => 'Оплата не пройшла',
        'refunded' => 'Повернено',
    ];

    /** Підміна транспорту для тестів: живих грошей у наборі бути не може. */
    public static ?Closure $transport = null;

    // ─────────────────────────────────────────────────────────────── налаштування

    public static function env(): string
    {
        $e = (string)Settings::get('acq_env', 'test');
        return isset(self::BASE[$e]) ? $e : 'test';
    }

    public static function base(): string
    {
        return self::BASE[self::env()];
    }

    /**
     * Середовище й адреса ПЛАТЕЖУ, а не поточних налаштувань.
     *
     * Різниця виявляється рівно тоді, коли її найдорожче виявляти: магазин
     * перемкнувся з тестового шлюзу на робочий (або на іншого постачальника), а
     * вчорашній платіж треба повернути. Питати про нього треба той шлюз, який
     * його прийняв, — інакше відповіддю буде «операцію не знайдено», і гроші
     * зависнуть між двома системами, жодна з яких їх не визнає.
     */
    public static function envOf(array $payment): string
    {
        $e = (string)($payment['env'] ?? '');
        return isset(self::BASE[$e]) ? $e : self::env();
    }

    public static function baseFor(array $payment): string
    {
        return self::BASE[self::envOf($payment)];
    }

    /**
     * Чи вміємо ми ще працювати з цим платежем.
     *
     * Порожньо — вміємо. Інакше рядок із поясненням: платіж прийняв
     * постачальник, якого в цій збірці більше немає, і робити з ним щось
     * наосліп означало б надсилати запити не туди.
     */
    public static function unsupported(array $payment): string
    {
        $p = (string)($payment['provider'] ?? '');
        if ($p === '' || isset(self::PROVIDERS[$p])) return '';
        return 'Цей платіж прийняв ' . self::providerLabel($p)
             . ' — операції з ним доступні лише в кабінеті того постачальника.';
    }

    public static function merchantId(): string { return trim((string)Settings::get('acq_merchant_id', '')); }
    public static function terminalId(): string { return trim((string)Settings::get('acq_terminal_id', '')); }

    /** Преавторизація: гроші блокуються, а списуються потім (див. capture) */
    public static function hold(): bool { return Settings::bool('acq_hold', false); }

    /**
     * Ключ підпису й сертифікат шлюзу.
     *
     * Спершу дивимось у файл, потім у налаштування. Файл перший не випадково:
     * приватний ключ у базі потрапляє в кожен дамп і в кожну копію бази на
     * ноутбуці розробника, а storage/keys вебсервером не віддається і в дамп
     * не входить. Налаштування лишились для хостингів без доступу до файлів.
     */
    public static function privateKey(): string
    {
        $f = self::keyFile('upc-private.pem');
        return $f !== '' ? $f : trim((string)Settings::get('acq_key', ''));
    }

    /**
     * $env — коли перевіряємо відповідь по платежу, проведеному в іншому
     * середовищі: сертифікати тестового й робочого шлюзів різні, і після
     * перемикання чинний уже не підійде до вчорашньої оплати.
     */
    public static function certificate(?string $env = null): string
    {
        $env = $env !== null && isset(self::BASE[$env]) ? $env : self::env();
        $f = self::keyFile('upc-' . $env . '.crt');
        if ($f !== '') return $f;
        // У налаштуваннях сертифікат один — того середовища, що ввімкнене
        // зараз. Для чужого середовища він не підійде, і це не помилка коду:
        // другий сертифікат просто нема де взяти, окрім файлів у storage/keys.
        return $env === self::env() ? trim((string)Settings::get('acq_cert', '')) : '';
    }

    public static function keyDir(): string
    {
        return BOFU_ROOT . '/storage/keys';
    }

    private static function keyFile(string $name): string
    {
        $path = self::keyDir() . '/' . $name;
        return is_file($path) ? trim((string)@file_get_contents($path)) : '';
    }

    /**
     * Чого бракує, щоб приймати оплату карткою.
     *
     * Список, а не «так/ні»: власник має бачити, який саме рядок заповнити і
     * що з цього він отримує від банку, а що робить сам.
     *
     * $override — те, що зараз стоїть у формі налаштувань, ще не збережене.
     * Потрібне перевірці зʼєднання: вона питає про ВПИСАНЕ, а не про
     * збережене, інакше «перевірити» перетворилось би на «спершу збережіть».
     *
     * @return string[]
     */
    public static function missing(array $override = []): array
    {
        $pick = static function (string $key, string $saved) use ($override): string {
            $v = trim((string)($override[$key] ?? ''));
            return $v !== '' ? $v : $saved;
        };
        $out = [];
        if (!extension_loaded('openssl')) $out[] = 'у PHP вимкнене розширення openssl — підписати запит нічим';
        if ($pick('acq_merchant_id', self::merchantId()) === '') $out[] = 'не вказано Merchant ID (видає банк)';
        if ($pick('acq_terminal_id', self::terminalId()) === '') $out[] = 'не вказано Terminal ID (видає банк)';

        $key = $pick('acq_key', self::privateKey());
        if ($key === '') $out[] = 'немає приватного ключа магазину';
        elseif (!self::loadKey($key)) $out[] = 'приватний ключ не читається — потрібен PEM (BEGIN PRIVATE KEY)';

        $cert = $pick('acq_cert', self::certificate());
        if ($cert === '') $out[] = 'немає сертифіката шлюзу — без нього неможливо перевірити відповідь про оплату';
        elseif (!self::loadCert($cert)) $out[] = 'сертифікат шлюзу не читається — потрібен PEM (BEGIN CERTIFICATE)';

        return $out;
    }

    /** Оплата карткою доступна покупцю */
    public static function enabled(): bool
    {
        return Settings::bool('acq_enabled', false) && !self::missing();
    }

    public static function label(): string
    {
        return 'Raiffeisen Bank — інтернет-еквайринг'
            . (self::env() === 'test' ? ' (тестовий шлюз)' : '');
    }

    public static function codeLabel(?string $code): string
    {
        $code = trim((string)$code);
        if ($code === '') return 'без коду';
        return self::CODES[$code] ?? ('Відмова, код ' . $code);
    }

    public static function statusLabel(?string $s): string
    {
        return self::STATUSES[(string)$s] ?? '—';
    }

    // ─────────────────────────────────────────────────────────────── підпис

    /**
     * Рядок для підпису платіжного запиту.
     *
     * Формат UPC: поля через «;», кількість «;» стала — відсутнє поле лишає
     * порожнє місце, а не зникає. Delay, AltCurrency й AltAmount туляться до
     * сусіда через кому, і якщо їх немає, кома теж не ставиться.
     *
     * Через це рядок збирається парами «основне поле + необовʼязковий доважок»,
     * а не конкатенацією списку: помилка на одну кому дає код 405 («шлюз не
     * прийняв підпис»), і шукати її потім у робочому середовищі, коли покупець
     * уже стоїть із карткою, дуже дорого.
     */
    public static function payData(array $p): string
    {
        $pair = static fn($a, $b) => (string)$a . ($b !== null && $b !== '' ? ',' . $b : '');
        $data = $p['merchant_id'] . ';' . $p['terminal_id'] . ';' . $p['purchase_time'] . ';'
              . $pair($p['order_ref'], !empty($p['hold']) ? '1' : null) . ';'
              . $pair($p['currency'], $p['alt_currency'] ?? null) . ';'
              . $pair($p['amount_minor'], $p['alt_amount'] ?? null) . ';'
              . (string)($p['sd'] ?? '') . ';';
        if (($p['ref3'] ?? '') !== '') $data .= $p['ref3'] . ';';
        return $data;
    }

    /**
     * Рядок для перевірки того, що прийшло від шлюзу.
     *
     * Відрізняється від платіжного трьома полями: XID усередині, TranCode і
     * ApprovalCode у кінці. Порядок узятий з документації дослівно — жодне
     * поле не можна пересунути «щоб було логічніше».
     */
    public static function notifyData(array $post): string
    {
        $pair = static fn($a, $b) => (string)$a . ($b !== null && $b !== '' ? ',' . $b : '');
        $get = static fn(string $k) => trim((string)($post[$k] ?? ''));
        $delay = $get('Delay');
        return $get('MerchantID') . ';' . $get('TerminalID') . ';' . $get('PurchaseTime') . ';'
             . $pair($get('OrderID'), $delay !== '' && $delay !== '0' ? $delay : null) . ';'
             . $get('XID') . ';'
             . $pair($get('Currency'), $get('AltCurrency') ?: null) . ';'
             . $pair($get('TotalAmount'), $get('AltTotalAmount') ?: null) . ';'
             . $get('SD') . ';' . $get('TranCode') . ';' . $get('ApprovalCode') . ';';
    }

    /** Підпис запиту: RSA + SHA-512, у Base64 — як у документації UPC */
    public static function sign(string $data): string
    {
        $key = self::loadKey(self::privateKey());
        if (!$key) return '';
        $sig = '';
        if (!@openssl_sign($data, $sig, $key, OPENSSL_ALGO_SHA512)) {
            self::log('підпис не сформувався: ' . self::opensslErrors());
            return '';
        }
        return base64_encode($sig);
    }

    /**
     * Перевірка підпису шлюзу.
     *
     * Приклад у документації викликає openssl_verify без алгоритму, тобто
     * SHA-1, тоді як запити від нас підписуються SHA-512. Довіритись одному
     * значенню тут не можна: помилимось — і КОЖНА оплата виглядатиме
     * підробленою, тобто гроші списані, а замовлення не оплачене. Тому
     * приймаємо будь-який із двох і пишемо в журнал, який саме спрацював.
     * Безпеки це не знижує: обидва підписи однаково зроблені ключем UPC, до
     * якого має доступ лише UPC.
     */
    public static function verify(string $data, string $signature, ?string $env = null): bool
    {
        $cert = self::loadCert(self::certificate($env));
        if (!$cert) { self::log('перевірка підпису: немає придатного сертифіката шлюзу'); return false; }
        $raw = base64_decode(trim($signature), true);
        if ($raw === false || $raw === '') return false;

        foreach ([OPENSSL_ALGO_SHA1 => 'sha1', OPENSSL_ALGO_SHA512 => 'sha512'] as $algo => $name) {
            if (@openssl_verify($data, $raw, $cert, $algo) === 1) {
                self::log('підпис шлюзу підтверджено (' . $name . ')');
                return true;
            }
        }
        return false;
    }

    /** @return \OpenSSLAsymmetricKey|false */
    private static function loadKey(string $pem)
    {
        if ($pem === '') return false;
        return @openssl_pkey_get_private($pem);
    }

    /** @return \OpenSSLAsymmetricKey|false */
    private static function loadCert(string $pem)
    {
        if ($pem === '') return false;
        return @openssl_pkey_get_public($pem);
    }

    private static function opensslErrors(): string
    {
        $out = [];
        while (($e = openssl_error_string()) !== false) $out[] = $e;
        return implode('; ', $out) ?: 'без пояснення';
    }

    // ─────────────────────────────────────────────────────────────── платіж

    public static function byRef(string $ref): ?array
    {
        return DB::row('SELECT * FROM payments WHERE order_ref = ?', [$ref]);
    }

    public static function byId(int $id): ?array
    {
        return DB::row('SELECT * FROM payments WHERE id = ?', [$id]);
    }

    /** Усі спроби оплати замовлення, свіжіші зверху */
    public static function forParent(int $parentId): array
    {
        return DB::all('SELECT * FROM payments WHERE parent_id = ? ORDER BY id DESC', [$parentId]);
    }

    /** Остання спроба — те, що показуємо покупцю й продавцю */
    public static function last(int $parentId): ?array
    {
        return DB::row('SELECT * FROM payments WHERE parent_id = ? ORDER BY id DESC LIMIT 1', [$parentId]);
    }

    /** Замовлення вже оплачене (або кошти по ньому заблоковані) */
    public static function settled(int $parentId): bool
    {
        return (int)DB::val("SELECT COUNT(*) FROM payments WHERE parent_id = ? AND status IN ('paid','held')",
            [$parentId]) > 0;
    }

    /**
     * Замовлення, які чекають на оплату карткою.
     *
     * Потрібні рівно в одному місці — коли власник вимикає оплату карткою.
     * Сам вимикач нічого не ламає, але ці замовлення лишаються з обіцянкою,
     * яку сайт більше не може виконати: покупець збирався заплатити
     * посиланням, а посилання відповість «оплата вимкнена». Хтось має їм
     * зателефонувати, і для цього треба знати, що вони є.
     *
     * Скасовані не рахуємо: платити за них не треба.
     */
    public static function pending(): array
    {
        return DB::all("SELECT id, number, total, created_at FROM orders
                        WHERE parent_id IS NULL AND payment_kind = 'card'
                          AND paid_at IS NULL AND status <> 'canceled'
                        ORDER BY id DESC");
    }

    /** Сума в копійках — саме в такому вигляді вона підписується й порівнюється */
    public static function minor(float $sum): int
    {
        return (int)round($sum * 100);
    }

    /**
     * Номер оплати для шлюзу.
     *
     * Не номер замовлення: шлюз відхиляє повтор OrderID, а друга спроба після
     * невдалої картки — звичайна справа. Тому до номера дописується спроба.
     * Обмеження у 20 символів витримується: BOFU-260801-A3F2-2 — вісімнадцять.
     */
    public static function makeRef(string $number, int $attempt): string
    {
        $ref = $attempt > 1 ? $number . '-' . $attempt : $number;
        return mb_substr($ref, 0, 20);
    }

    /**
     * Почати оплату: створити рядок платежу й зібрати форму для шлюзу.
     *
     * Повертає ['ok','error','payment','action','fields'] — сама відправка
     * робиться браузером покупця, бо саме він має опинитись на сторінці UPC.
     */
    public static function start(array $parent, ?int $userId = null): array
    {
        /*
         * Вимикач перевіряється саме тут, а не лише у формі оформлення.
         *
         * Кнопка на сторінці оплати — не єдиний вхід сюди: у покупця лишається
         * посилання з листа й із кабінету, відкрите з учорашньої вкладки. Якби
         * вимикач діяв тільки на показ кнопки, магазин, який щойно припинив
         * приймати картки, ще добу приймав би їх від тих, хто вже мав адресу.
         */
        if (!Settings::bool('acq_enabled', false)) {
            return ['ok' => false, 'error' => 'Оплата карткою на сайті наразі вимкнена — '
                . 'продавець зателефонує й узгодить зручний спосіб оплати.'];
        }
        $gaps = self::missing();
        if ($gaps) return ['ok' => false, 'error' => 'Оплата карткою недоступна: ' . implode('; ', $gaps) . '.'];

        $parentId = (int)$parent['id'];
        $sum = round((float)$parent['total'], 2);
        if ($sum <= 0) return ['ok' => false, 'error' => 'Сума замовлення нульова — оплачувати нічого.'];
        if (self::settled($parentId)) return ['ok' => false, 'error' => 'Це замовлення вже оплачене.'];

        // Незавершені спроби закриваємо: покупець пішов другим колом, і дві
        // «відкриті» оплати на одне замовлення потім неможливо звести
        DB::update('payments', ['status' => 'failed', 'error' => 'Покупець почав нову спробу', 'updated_at' => now()],
            "parent_id = ? AND status IN ('new','sent')", [$parentId]);

        $attempt = (int)DB::val('SELECT COUNT(*) FROM payments WHERE parent_id = ?', [$parentId]) + 1;
        $ref = self::makeRef((string)$parent['number'], $attempt);
        // Збіг можливий після ручного прибирання рядків — краще впертись тут,
        // ніж отримати від шлюзу 412 на очах у покупця
        while (self::byRef($ref)) {
            $attempt++;
            $ref = self::makeRef((string)$parent['number'], $attempt);
        }

        $row = [
            'parent_id' => $parentId,
            'order_ref' => $ref,
            'attempt' => $attempt,
            'provider' => self::PROVIDER,
            'env' => self::env(),
            'merchant_id' => self::merchantId(),
            'terminal_id' => self::terminalId(),
            'amount' => $sum,
            'currency' => '980',
            // Час підписується й бере участь у кожному подальшому запиті —
            // тому фіксуємо його тут і більше ніколи не перераховуємо
            'purchase_time' => date('ymdHis'),
            'hold' => self::hold() ? 1 : 0,
            'status' => 'new',
            'created_at' => now(),
        ];
        $row['id'] = DB::insert('payments', $row);

        $fields = self::payFields($row, $parent);
        if (($fields['Signature'] ?? '') === '') {
            DB::update('payments', ['status' => 'failed', 'error' => 'Не вдалося підписати запит', 'updated_at' => now()],
                'id = ?', [$row['id']]);
            return ['ok' => false, 'error' => 'Не вдалося підписати платіжний запит — перевірте ключ у налаштуваннях.'];
        }

        DB::update('payments', ['status' => 'sent', 'updated_at' => now()], 'id = ?', [$row['id']]);
        $row['status'] = 'sent';
        OrderFlow::log($parentId, null, 'note',
            'Покупця відправлено на оплату карткою, номер оплати ' . $ref . '.', $userId);
        self::log("start #{$row['id']} $ref " . number_format($sum, 2, '.', '') . ' грн');

        return ['ok' => true, 'error' => '', 'payment' => $row,
                'action' => self::base() . '/go/pay', 'fields' => $fields];
    }

    /** Поля форми на шлюз — рівно ті, що описані в документації UPC */
    public static function payFields(array $payment, array $parent): array
    {
        $minor = self::minor((float)$payment['amount']);
        $sign = self::payData([
            'merchant_id' => $payment['merchant_id'], 'terminal_id' => $payment['terminal_id'],
            'purchase_time' => $payment['purchase_time'], 'order_ref' => $payment['order_ref'],
            'hold' => !empty($payment['hold']), 'currency' => $payment['currency'],
            'amount_minor' => $minor, 'sd' => '',
        ]);

        $fields = [
            'Version' => '1',
            'MerchantID' => (string)$payment['merchant_id'],
            'TerminalID' => (string)$payment['terminal_id'],
            'TotalAmount' => (string)$minor,
            'Currency' => (string)$payment['currency'],
            'locale' => 'uk',
            'PurchaseTime' => (string)$payment['purchase_time'],
            'OrderID' => (string)$payment['order_ref'],
            'PurchaseDesc' => self::desc($parent),
            'Signature' => self::sign($sign),
        ];
        if (!empty($payment['hold'])) $fields['Delay'] = '1';

        // Пошта й телефон — не для нас, а для покупця: банк надішле йому чек
        // про операцію, а шлюз підставить контакти у форму 3D Secure. Поля
        // необовʼязкові й у підпис не входять.
        $email = trim((string)($parent['email'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) $fields['email'] = $email;
        $phone = preg_replace('/\D+/', '', (string)($parent['phone'] ?? '')) ?? '';
        if (strlen($phone) === 12 && str_starts_with($phone, '380')) {
            $fields['phoneCountryCode'] = '380';
            $fields['phoneNumber'] = substr($phone, 3);
        }
        return $fields;
    }

    /**
     * Призначення платежу.
     *
     * Його покупець побачить у виписці банку, тому це має бути номер, за яким
     * він упізнає покупку, а не назва товару: у замовленні з трьох позицій
     * назва однієї збиває з пантелику сильніше, ніж її відсутність. Чистимо
     * керівні символи й довжину (125 за специфікацією).
     */
    public static function desc(array $parent): string
    {
        $tpl = trim((string)Settings::get('acq_desc', ''));
        if ($tpl === '') $tpl = 'Замовлення {number} на сайті {shop}';
        $text = strtr($tpl, ['{number}' => (string)($parent['number'] ?? ''), '{shop}' => (string)cfg('app_name')]);
        $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text) ?? '';
        return mb_substr(trim(preg_replace('/\s+/u', ' ', $text) ?? ''), 0, 125);
    }

    // ─────────────────────────────────────────────────────────────── відповідь шлюзу

    /**
     * NOTIFY_URL: шлюз повідомляє результат напряму нашому серверу.
     *
     * Це і є єдине джерело правди про оплату. Браузер покупця може не
     * повернутись узагалі — закритий ноутбук, обірваний інтернет, антивірус,
     * що переписує сторінки, — а гроші при цьому вже списані.
     *
     * Відповідь шлюзу — не HTML, а рядки «Ключ=Значення». 'approve' означає
     * «магазин згоден завершити операцію»; 'reverse' скасовує списання. Ми
     * відповідаємо reverse лише тоді, коли не можемо звести платіж із
     * замовленням: краще повернути гроші відразу, ніж лишити покупця з
     * невідомим списанням.
     *
     * ВИМИКАЧ «приймати оплату карткою» тут НЕ перевіряється, і це навмисно.
     * Сюди приходить звіт про гроші, які вже пішли з картки: людина натиснула
     * «оплатити» за хвилину до того, як власник зняв галку. Відмовитись від
     * такого повідомлення означало б лишити оплачене замовлення неоплаченим —
     * найгірший спосіб вимкнути оплату з можливих. Вимикач зупиняє НОВІ спроби
     * (див. start), а не вже початі.
     *
     * @return array{body:string,payment:?array,ok:bool}
     */
    public static function handleNotify(array $post, string $ip = ''): array
    {
        $ref = trim((string)($post['OrderID'] ?? ''));
        $code = trim((string)($post['TranCode'] ?? ''));
        self::log("notify $ref код=" . ($code !== '' ? $code : '—') . ' ip=' . ($ip !== '' ? $ip : '—'));

        $known = self::NOTIFY_IPS[self::env()] ?? [];
        if ($ip !== '' && $known && !in_array($ip, $known, true)) {
            // Не відмовляємо — див. коментар до NOTIFY_IPS. Але слід лишаємо:
            // якщо й підпис не зійдеться, у журналі вже буде видно чому.
            self::log("notify $ref: незнайомий IP $ip (очікувані: " . implode(', ', $known) . ')');
        }

        $payment = $ref !== '' ? self::byRef($ref) : null;
        if (!$payment) {
            self::log("notify $ref: платежу з таким номером у нас немає — просимо скасувати");
            return ['ok' => false, 'payment' => null,
                    'body' => self::notifyBody($post, 'reverse', 'Order not found')];
        }

        // Сертифікат беремо за середовищем САМОГО ПЛАТЕЖУ: магазин міг щойно
        // перемкнутися з тестового шлюзу на робочий, і чинний сертифікат до
        // вчорашньої оплати не підійде. Не зійдеться підпис — гроші
        // повернуться покупцю, тобто помилка тут коштує живої оплати.
        if (!self::verify(self::notifyData($post), (string)($post['Signature'] ?? ''), self::envOf($payment))) {
            /*
             * Підпис не зійшовся — отже, це не шлюз. Тому НІЧОГО не пишемо в
             * платіж: лише журнал і відмова.
             *
             * Раніше тут стояв fail(), і це був єдиний спосіб змінити стан
             * платежу без жодної автентифікації. Номер оплати — це номер
             * замовлення, тобто рядок із шести символів після дати; підібравши
             * його, стороння людина могла позначити чужу незавершену оплату
             * як невдалу. Само по собі це не крало грошей і полагодилось би
             * справжнім NOTIFY, але продавець тим часом бачив у картці
             * «оплата не пройшла» й міг зателефонувати покупцеві з цим.
             *
             * Стан платежу тепер міняє лише те, що доведено підписом. Невдала
             * спроба покупця й далі позначається як failed — але з відповіді
             * шлюзу (apply), а не з чийогось POST.
             */
            self::log("notify $ref: ПІДПИС НЕ ЗІЙШОВСЯ (стан платежу не змінюємо), дані: " . self::notifyData($post));
            return ['ok' => false, 'payment' => $payment,
                    'body' => self::notifyBody($post, 'reverse', 'Signature check failed')];
        }

        // Сума й реквізити мають збігатися з тим, що ми відправляли. Розбіжність
        // тут — не «дрібна невідповідність», а підстава не віддавати товар.
        $mismatch = self::mismatch($post, $payment);
        if ($mismatch !== '') {
            self::log("notify $ref: $mismatch");
            self::fail($payment, $code, $mismatch);
            return ['ok' => false, 'payment' => $payment,
                    'body' => self::notifyBody($post, 'reverse', 'Amount mismatch')];
        }

        $payment = self::apply($payment, $post);

        // Динамічне посилання замість статичного SUCCESS_URL: у терміналі
        // прописана одна адреса на всі оплати, а покупця треба привести саме
        // до його замовлення
        $forward = '';
        $parent = OrderFlow::order((int)$payment['parent_id']);
        if ($parent && ($parent['token'] ?? '')) $forward = abs_url('/order/success/' . $parent['token']);

        return ['ok' => self::ok($code), 'payment' => $payment,
                'body' => self::notifyBody($post, 'approve', 'ok', $forward)];
    }

    /** Тіло відповіді шлюзу: рядки «Ключ=Значення», як описано в UPC */
    private static function notifyBody(array $post, string $action, string $reason = '', string $forward = ''): string
    {
        $lines = [];
        foreach (['MerchantID', 'TerminalID', 'OrderID', 'Currency', 'TotalAmount', 'XID', 'PurchaseTime'] as $k) {
            $lines[] = $k . '=' . trim((string)($post[$k] ?? ''));
        }
        $lines[] = 'Response.action=' . $action;
        $lines[] = 'Response.reason=' . $reason;
        $lines[] = 'Response.forwardUrl=' . $forward;
        return implode("\n", $lines) . "\n";
    }

    /** Що саме не збіглося з нашим платежем; порожньо — усе гаразд */
    public static function mismatch(array $post, array $payment): string
    {
        $got = (int)round((float)($post['TotalAmount'] ?? 0));
        $want = self::minor((float)$payment['amount']);
        if ($got !== $want) return "сума не збігається: шлюз каже $got коп., у нас $want коп.";
        if (trim((string)($post['MerchantID'] ?? '')) !== (string)$payment['merchant_id']) {
            return 'Merchant ID у відповіді не наш';
        }
        if (trim((string)($post['TerminalID'] ?? '')) !== (string)$payment['terminal_id']) {
            return 'Terminal ID у відповіді не наш';
        }
        if (trim((string)($post['Currency'] ?? '')) !== (string)$payment['currency']) {
            return 'валюта у відповіді не наша';
        }
        return '';
    }

    public static function ok(?string $code): bool
    {
        return trim((string)$code) === self::OK;
    }

    /**
     * Записати результат у платіж і, якщо оплата пройшла, — у замовлення.
     *
     * Ідемпотентна навмисно: NOTIFY приходить повторно, покупець оновлює
     * сторінку повернення, cron звіряє статус — і всі троє можуть потрапити
     * сюди з тим самим результатом. Двічі позначити оплату означало б двічі
     * пробити чек.
     */
    public static function apply(array $payment, array $post): array
    {
        $code = trim((string)($post['TranCode'] ?? ''));
        $paid = self::ok($code);
        $held = $paid && !empty($payment['hold']);
        $was = (string)$payment['status'];

        $set = [
            'tran_code' => $code !== '' ? $code : null,
            'approval_code' => trim((string)($post['ApprovalCode'] ?? '')) ?: null,
            'rrn' => trim((string)($post['Rrn'] ?? ($post['RRN'] ?? ''))) ?: null,
            'xid' => trim((string)($post['XID'] ?? '')) ?: null,
            'proxy_pan' => trim((string)($post['ProxyPan'] ?? '')) ?: null,
            'wallet' => trim((string)($post['WalletType'] ?? '')) ?: null,
            'notified_at' => now(),
            'updated_at' => now(),
            'raw' => json_encode(self::safeRaw($post), JSON_UNESCAPED_UNICODE),
        ];
        if ($paid) {
            $set['status'] = $held ? 'held' : 'paid';
            $set['error'] = null;
            if (($payment['paid_at'] ?? null) === null) $set['paid_at'] = now();
        } elseif (!in_array($was, ['paid', 'held', 'refunded'], true)) {
            $set['status'] = 'failed';
            $set['error'] = self::codeLabel($code);
        }
        DB::update('payments', $set, 'id = ?', [(int)$payment['id']]);
        $fresh = self::byId((int)$payment['id']) ?? array_replace($payment, $set);

        if ($paid) self::markOrderPaid($fresh);
        return $fresh;
    }

    /** Невдала спроба без відповіді шлюзу (підпис, розбіжність сум) */
    private static function fail(array $payment, string $code, string $why): void
    {
        if (in_array((string)$payment['status'], ['paid', 'held', 'refunded'], true)) return;
        DB::update('payments', [
            'status' => 'failed', 'tran_code' => $code !== '' ? $code : null,
            'error' => $why, 'updated_at' => now(),
        ], 'id = ?', [(int)$payment['id']]);
    }

    /**
     * У відповіді шлюзу карткових даних немає, окрім маскованого номера, але
     * підпис зберігати теж ні до чого: журнал платежу читає продавець, а не
     * аудитор безпеки.
     */
    private static function safeRaw(array $post): array
    {
        unset($post['Signature']);
        return array_map(static fn($v) => is_scalar($v) ? (string)$v : '', $post);
    }

    /**
     * Замовлення оплачене: позначка, запис у стрічку подій, лист покупцю і —
     * за потреби — фіскальний чек.
     *
     * Оплата карткою на сайті потребує фіскального чека так само, як оплата
     * карткою в магазині: розрахункова операція вже відбулась. Тому чек
     * ставиться в чергу відразу, а не «коли продавець згадає». Точки без
     * заведеної каси мовчки пропускаються — як і в касовому продажу.
     */
    public static function markOrderPaid(array $payment): void
    {
        $parent = OrderFlow::order((int)$payment['parent_id']);
        if (!$parent) return;
        if ((string)($parent['paid_at'] ?? '') !== '') return;   // вже позначено — не повторюємо

        DB::update('orders', [
            'payment_kind' => 'card',
            'paid_at' => (string)($payment['paid_at'] ?? '') ?: now(),
        ], 'id = ?', [(int)$parent['id']]);

        /*
         * Куплений курс відкривається саме тут — гроші прийшли.
         *
         * Не за статусом «Доставлено»: його ставить продавець рукою, а до
         * цифрового замовлення він і не застосовний — везти нема чого. Доступ
         * дають гроші, і місце, де це відомо, рівно одне.
         */
        Courses::grantFor((int)$parent['id'], $parent['user_id'] ? (int)$parent['user_id'] : null);

        $held = (string)$payment['status'] === 'held';
        $sum = number_format((float)$payment['amount'], 2, '.', ' ');
        OrderFlow::log((int)$parent['id'], null, 'note',
            ($held ? 'Кошти заблоковано на картці' : 'Оплату карткою отримано')
            . ': ' . $sum . ' грн, номер оплати ' . $payment['order_ref']
            . ($payment['approval_code'] ? ', код авторизації ' . $payment['approval_code'] : '')
            . ($payment['rrn'] ? ', RRN ' . $payment['rrn'] : '') . '.');

        // Продавцю — щоб не дзвонив уточнювати оплату; покупцю — щоб бачив, що
        // гроші дійшли, а не «списались невідомо куди»
        Notify::fire('payment_paid', [
            'number' => (string)$parent['number'],
            'total' => $sum,
            'card' => (string)($payment['proxy_pan'] ?? ''),
            'ref' => (string)$payment['order_ref'],
            'state' => $held ? 'кошти заблоковано (потрібне списання)' : 'зараховано',
        ]);
        Notify::toCustomer(
            $parent['user_id'] ? (int)$parent['user_id'] : null,
            ((string)($parent['email'] ?? '')) !== '' ? (string)$parent['email'] : null,
            'order_customer',
            OrderFlow::customerVars($parent, $held ? 'оплату підтверджено' : 'оплачено карткою'));

        if (!$held) self::fiscalize($parent);
    }

    /**
     * Чеки за онлайн-оплатою.
     *
     * Спосіб оплати в чеку — «Картка» (тип 2 у переліку ПРРО): гроші прийшли
     * карткою, і чек має казати саме це, інакше Z-звіт не зійдеться з випискою
     * еквайєра.
     */
    public static function fiscalize(array $parent): void
    {
        if (!Settings::bool('acq_auto_fiscal', true)) return;
        $children = OrderFlow::children((int)$parent['id']);
        if (!$children) return;
        $r = Fiscal::afterPosSale($children, $parent, ['pay_type' => 2], null);
        foreach ($r['errors'] as $e) self::log('чек за онлайн-оплатою: ' . $e);
    }

    // ─────────────────────────────────────────────────────────────── звірка

    /**
     * Запит статусу операції на шлюзі.
     *
     * Потрібен там, де NOTIFY не дійшов: локальна розробка без публічної
     * адреси, впав наш сервер, брандмауер відкинув чужий IP. Це той самий
     * результат із того самого джерела, тільки спитаний нами.
     *
     * @return array{ok:bool,error:string,code:string,payment:array}
     */
    public static function sync(array $payment): array
    {
        // Звірка, списання й повернення працюють НЕЗАЛЕЖНО від вимикача: гроші
        // вже рухались, і вимкнене приймання нових оплат не має перетворювати
        // вчорашні на нерозв'язні. Єдине, що їх зупиняє, — постачальник, якого
        // ця збірка більше не знає.
        $no = self::unsupported($payment);
        if ($no !== '') return ['ok' => false, 'error' => $no, 'code' => '', 'payment' => $payment];

        $fields = [
            'MerchantID' => (string)$payment['merchant_id'],
            'TerminalID' => (string)$payment['terminal_id'],
            'OrderID' => (string)$payment['order_ref'],
            'Currency' => (string)$payment['currency'],
            'TotalAmount' => (string)self::minor((float)$payment['amount']),
            'PurchaseTime' => (string)$payment['purchase_time'],
        ];
        $res = self::post('/go/service/01', $fields, self::baseFor($payment));
        if (!$res['ok']) return ['ok' => false, 'error' => $res['error'], 'code' => '', 'payment' => $payment];

        $vals = self::parse($res['body']);
        $code = trim((string)($vals['TranCode'] ?? ''));
        if ($code === '') {
            return ['ok' => false, 'error' => 'Шлюз не назвав стан операції', 'code' => '', 'payment' => $payment];
        }
        // Відповідь на службовий запит підпису не має — її не можна перевірити
        // так само, як NOTIFY. Але й підробити її не можна: ми самі пішли на
        // адресу шлюзу по HTTPS із перевіркою сертифіката. Тому застосовуємо,
        // доповнивши тим, що вже знаємо про платіж.
        $fresh = self::apply($payment, array_merge($fields, [
            'TranCode' => $code,
            'ApprovalCode' => trim((string)($vals['ApprovalCode'] ?? '')),
            'XID' => trim((string)($vals['XID'] ?? '')),
            'Rrn' => trim((string)($vals['Rrn'] ?? ($vals['RRN'] ?? ''))),
        ]));
        self::log("sync {$payment['order_ref']}: код $code");
        return ['ok' => self::ok($code), 'error' => self::ok($code) ? '' : self::codeLabel($code),
                'code' => $code, 'payment' => $fresh];
    }

    /**
     * Платежі, які варто перепитати: покупця відправили на оплату, а відповіді
     * так і не дочекались. Свіжіші за добу — далі шлюз усе одно закриє сесію.
     */
    public static function due(int $limit = 50): array
    {
        $since = date('Y-m-d H:i:s', time() - 86400);
        return DB::all("SELECT * FROM payments
                        WHERE status IN ('new','sent') AND created_at > ?
                        ORDER BY id LIMIT " . max(1, min(500, $limit)), [$since]);
    }

    // ─────────────────────────────────────────────────────────────── списання й повернення

    /**
     * Завершити преавторизацію — списати заблоковані кошти.
     *
     * Сума може бути меншою за заблоковану (частину товару не знайшли), але не
     * більшою ніж на 20% — це правило шлюзу, і порушення дає код 508. Різницю
     * банк покупця розблокує у свій строк.
     */
    public static function capture(array $payment, ?float $amount = null, ?int $userId = null): array
    {
        $no = self::unsupported($payment);
        if ($no !== '') return ['ok' => false, 'error' => $no];
        if ((string)$payment['status'] !== 'held') {
            return ['ok' => false, 'error' => 'Списувати можна лише заблоковані кошти.'];
        }
        $sum = round($amount === null ? (float)$payment['amount'] : $amount, 2);
        if ($sum <= 0) return ['ok' => false, 'error' => 'Сума списання має бути більшою за нуль.'];
        if ($sum > round((float)$payment['amount'] * 1.2, 2)) {
            return ['ok' => false, 'error' => 'Списати можна не більше ніж на 20% понад заблоковану суму.'];
        }

        $minor = self::minor($sum);
        // Підпис збирається тим самим рядком, що й платіж, — окремого формату
        // для завершення преавторизації документація UPC не описує. Якщо шлюз
        // відповість кодом 405 саме на списання (а на оплату — ні), причина
        // буде тут, і виправлення теж: банк дасть точний склад полів.
        $data = self::payData([
            'merchant_id' => $payment['merchant_id'], 'terminal_id' => $payment['terminal_id'],
            'purchase_time' => $payment['purchase_time'], 'order_ref' => $payment['order_ref'],
            'hold' => false, 'currency' => $payment['currency'], 'amount_minor' => $minor, 'sd' => '',
        ]);
        $res = self::post('/go/capture', [
            'MerchantID' => (string)$payment['merchant_id'],
            'TerminalID' => (string)$payment['terminal_id'],
            'OrderID' => (string)$payment['order_ref'],
            'Currency' => (string)$payment['currency'],
            'TotalAmount' => (string)$minor,
            'PurchaseTime' => (string)$payment['purchase_time'],
            'Signature' => self::sign($data),
        ], self::baseFor($payment));
        if (!$res['ok']) return ['ok' => false, 'error' => $res['error']];

        $vals = self::parse($res['body']);
        $code = trim((string)($vals['TranCode'] ?? ''));
        if (!self::ok($code)) {
            self::log("capture {$payment['order_ref']}: відмова $code");
            return ['ok' => false, 'error' => self::codeLabel($code)];
        }
        DB::update('payments', [
            'status' => 'paid', 'amount' => $sum, 'hold' => 0,
            'paid_at' => (string)($payment['paid_at'] ?? '') ?: now(), 'updated_at' => now(),
        ], 'id = ?', [(int)$payment['id']]);

        $parent = OrderFlow::order((int)$payment['parent_id']);
        if ($parent) {
            OrderFlow::log((int)$parent['id'], null, 'note',
                'Заблоковані кошти списано: ' . number_format($sum, 2, '.', ' ') . ' грн.', $userId);
            self::fiscalize($parent);
        }
        self::log("capture {$payment['order_ref']}: списано " . number_format($sum, 2, '.', ''));
        return ['ok' => true, 'error' => '', 'payment' => self::byId((int)$payment['id'])];
    }

    /**
     * Повернення коштів покупцю.
     *
     * Порожня сума означає повне повернення. Часткові шлюз теж підтримує
     * (RefundAmount), і саме вони потрібні, коли із замовлення скасували одну
     * позицію.
     *
     * Фіскального чека повернення тут НЕ пробиваємо: чек повертає той самий
     * ПРРО, що й пробив продаж, і робиться це в картці замовлення — інакше
     * один продавець повернув би гроші, а другий, не знаючи, повернув би чек
     * ще раз.
     */
    public static function refund(array $payment, ?float $amount = null, ?int $userId = null): array
    {
        $no = self::unsupported($payment);
        if ($no !== '') return ['ok' => false, 'error' => $no];
        if (!in_array((string)$payment['status'], ['paid', 'refunded'], true)) {
            return ['ok' => false, 'error' => 'Повертати можна лише успішну оплату.'];
        }
        if (trim((string)($payment['approval_code'] ?? '')) === '' || trim((string)($payment['rrn'] ?? '')) === '') {
            return ['ok' => false, 'error' => 'Немає коду авторизації або RRN — повернення через API неможливе, зробіть його в кабінеті банку.'];
        }
        $total = round((float)$payment['amount'], 2);
        $already = round((float)($payment['refunded'] ?? 0), 2);
        $left = round($total - $already, 2);
        if ($left <= 0) return ['ok' => false, 'error' => 'За цією оплатою вже повернено все.'];

        $sum = round($amount === null ? $left : $amount, 2);
        if ($sum <= 0) return ['ok' => false, 'error' => 'Сума повернення має бути більшою за нуль.'];
        if ($sum > $left) {
            return ['ok' => false, 'error' => 'Максимум до повернення — ' . number_format($left, 2, '.', ' ') . ' грн.'];
        }

        $minor = self::minor($total);
        // Повне повернення — реверсал: RefundAmount не передається й у підпис
        // не входить. Часткове — із сумою. Переплутати означає код 405.
        $full = $already === 0.0 && abs($sum - $total) < 0.005;
        $data = $payment['merchant_id'] . ';' . $payment['terminal_id'] . ';' . $payment['purchase_time'] . ';'
              . $payment['order_ref'] . ';' . $payment['currency'] . ';' . $minor . ';;'
              . $payment['approval_code'] . ';' . $payment['rrn'] . ';'
              . ($full ? '' : self::minor($sum) . ';');

        $fields = [
            'MerchantID' => (string)$payment['merchant_id'],
            'TerminalID' => (string)$payment['terminal_id'],
            'OrderID' => (string)$payment['order_ref'],
            'Currency' => (string)$payment['currency'],
            'TotalAmount' => (string)$minor,
            'PurchaseTime' => (string)$payment['purchase_time'],
            'ApprovalCode' => (string)$payment['approval_code'],
            'RRN' => (string)$payment['rrn'],
            'Signature' => self::sign($data),
        ];
        if (!$full) $fields['RefundAmount'] = (string)self::minor($sum);

        $res = self::post('/go/repayment', $fields, self::baseFor($payment));
        if (!$res['ok']) return ['ok' => false, 'error' => $res['error']];

        $vals = self::parse($res['body']);
        $code = trim((string)($vals['TranCode'] ?? ''));
        if (!self::ok($code)) {
            $why = trim((string)($vals['ERROR'] ?? '')) ?: self::codeLabel($code);
            self::log("refund {$payment['order_ref']}: відмова $code $why");
            return ['ok' => false, 'error' => $why];
        }

        $done = round($already + $sum, 2);
        $whole = $done >= $total - 0.005;
        DB::update('payments', [
            'refunded' => $done,
            'status' => $whole ? 'refunded' : 'paid',
            'card_scheme' => trim((string)($vals['CardScheme'] ?? '')) ?: ($payment['card_scheme'] ?? null),
            'updated_at' => now(),
        ], 'id = ?', [(int)$payment['id']]);

        $parent = OrderFlow::order((int)$payment['parent_id']);
        if ($parent) {
            OrderFlow::log((int)$parent['id'], null, 'note',
                'Повернення на картку: ' . number_format($sum, 2, '.', ' ') . ' грн'
                . ($whole ? '' : ' (частково)') . '.', $userId);
            // Позначку про оплату знімаємо лише при повному поверненні: після
            // часткового замовлення лишається оплаченим — просто на меншу суму
            if ($whole) DB::update('orders', ['paid_at' => null], 'id = ?', [(int)$parent['id']]);
        }
        self::log("refund {$payment['order_ref']}: повернено " . number_format($sum, 2, '.', ''));
        return ['ok' => true, 'error' => '', 'payment' => self::byId((int)$payment['id'])];
    }

    // ─────────────────────────────────────────────────────────────── транспорт

    /**
     * POST на шлюз. Відповідь — не JSON і не XML, а текстова сторінка з
     * прихованими input-ами; розбираємо її нижче.
     *
     * @return array{ok:bool,body:string,error:string}
     */
    private static function post(string $path, array $fields, ?string $base = null): array
    {
        // Адреса приходить від платежу, а не з налаштувань: після перемикання
        // середовища запит про вчорашню оплату має йти на вчорашній шлюз
        $base = $base ?? self::base();
        if (self::$transport) {
            $r = (self::$transport)(['path' => $path, 'fields' => $fields, 'base' => $base]);
            return is_array($r)
                ? $r + ['ok' => true, 'body' => '', 'error' => '']
                : ['ok' => false, 'body' => '', 'error' => 'транспорт не відповів'];
        }
        $ch = curl_init($base . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            // Сертифікат шлюзу перевіряємо: без цього підміна на шляху
            // перетворює «оплачено» на слово, яке може сказати будь-хто
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            self::log("POST $path: мережа — $err");
            return ['ok' => false, 'body' => '', 'error' => 'Шлюз не відповідає' . ($err !== '' ? ": $err" : '')];
        }
        if ($http >= 400) {
            self::log("POST $path: HTTP $http");
            return ['ok' => false, 'body' => (string)$raw, 'error' => "Шлюз відповів помилкою HTTP $http"];
        }
        return ['ok' => true, 'body' => (string)$raw, 'error' => ''];
    }

    /**
     * Розібрати відповідь шлюзу.
     *
     * Вона приходить сторінкою з прихованими полями (<input name=".." value="..">),
     * а службові запити інколи відповідають простими рядками «Ключ=Значення».
     * Приймаємо обидва вигляди: який саме прийде, залежить від налаштувань
     * терміналу, і дізнатись це наперед неможливо.
     *
     * @return array<string,string>
     */
    public static function parse(string $body): array
    {
        $out = [];
        if (preg_match_all('~<input[^>]*\bname\s*=\s*["\']?([A-Za-z0-9_.]+)["\']?[^>]*\bvalue\s*=\s*["\']([^"\']*)["\']~i',
            $body, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) $out[$hit[1]] = trim($hit[2]);
        }
        if (!$out) {
            foreach (preg_split('/\r\n|\r|\n/', $body) ?: [] as $line) {
                if (!str_contains($line, '=')) continue;
                [$k, $v] = explode('=', $line, 2);
                $k = trim(strip_tags($k));
                if (preg_match('/^[A-Za-z0-9_.]+$/', $k)) $out[$k] = trim(strip_tags($v), " \t\"'");
            }
        }
        return $out;
    }

    public static function log(string $msg): void
    {
        $dir = BOFU_ROOT . '/storage/logs';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        @file_put_contents($dir . '/acquiring.log', now() . ' ' . $msg . "\n", FILE_APPEND);
    }
}
