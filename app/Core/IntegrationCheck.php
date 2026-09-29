<?php
declare(strict_types=1);

/**
 * Перевірка налаштувань інтеграцій — «а воно взагалі працює?».
 *
 * Причина існування: історія з Viber. Токен був правильний, поле заповнене,
 * а кнопка входу не показувалась — бо запит до API падав, і ніде про це не
 * писалось. Налаштування, яке мовчки не працює, гірше за незаповнене:
 * незаповнене видно.
 *
 * Два правила, від яких не відступаємо:
 *
 * 1. Перевіряємо ТЕ, ЩО ВПИСАНО У ФОРМУ, не зберігаючи. Інакше «перевірити»
 *    ставало б «зберегти», і помилковий токен лишався б у базі. Порожнє поле
 *    ключа — не «немає ключа», а «не змінював»: форма збережених ключів більше
 *    не показує, тож для них береться збережене значення (див. run).
 * 2. Тільки читальні виклики. Жодного set_webhook (перепише бота, який може
 *    обслуговувати інший сайт) і жодних тестових листів чи повідомлень —
 *    вони йдуть живим людям.
 *
 * Кожна перевірка чесно каже, чого вона НЕ доводить.
 */
class IntegrationCheck
{
    /** @return array<int, array{name:string, state:string, text:string, note?:string}> */
    public static function run(array $v): array
    {
        /*
         * Порожнє поле ключа означає «не змінював», а не «немає ключа».
         *
         * Форма налаштувань більше не повертає збережені ключі в HTML (див.
         * SettingsAdmin::SECRET_KEYS), тож у POST вони приходять порожніми
         * щоразу, коли людина їх не чіпала. Без цієї підстановки кнопка
         * «Перевірити зʼєднання» повідомляла б, що всі інтеграції вимкнені, —
         * на сайті, де вони працюють. Найгірший різновид помилки: вона змушує
         * піти й «полагодити» те, що не зламане.
         *
         * Вписане в поле й далі старше за збережене: перевірити НОВИЙ ключ до
         * збереження — це і є те, заради чого кнопка існує.
         */
        foreach (['telegram_bot_token', 'viber_bot_token', 'np_api_key',
                  'vchasno_token', 'google_client_secret', 'google_client_id'] as $key) {
            if (trim((string)($v[$key] ?? '')) === '') {
                $saved = trim((string)Settings::get($key, ''));
                if ($saved !== '') $v[$key] = $saved;
            }
        }

        return array_values(array_filter([
            self::telegram(trim((string)($v['telegram_bot_token'] ?? ''))),
            self::viber(trim((string)($v['viber_bot_token'] ?? ''))),
            self::novaPoshta(trim((string)($v['np_api_key'] ?? ''))),
            self::npSender(trim((string)($v['np_api_key'] ?? ''))),
            self::vchasno(trim((string)($v['vchasno_token'] ?? ''))),
            self::acquiring((array)($v['acq'] ?? [])),
            self::google($v),
            self::email($v),
            self::botSite(trim((string)($v['bot_site_url'] ?? ''))),
            self::push(),
        ]));
    }

    private static function row(string $name, string $state, string $text, ?string $note = null): array
    {
        $r = ['name' => $name, 'state' => $state, 'text' => $text];
        if ($note !== null) $r['note'] = $note;
        return $r;
    }

    private static function telegram(string $token): array
    {
        if ($token === '') return self::row('Telegram', 'off', 'Токен не вказано — вхід і сповіщення через Telegram вимкнені');
        Telegram::useToken($token);
        try {
            $me = Telegram::api('getMe');
        } finally {
            Telegram::useToken(null);
        }
        $r = $me['result'] ?? null;
        if (!$r) {
            return self::row('Telegram', 'bad',
                'Telegram не прийняв токен: ' . ($me['description'] ?? 'немає відповіді'),
                'Перевірте, що це токен від @BotFather і що сервер має доступ до api.telegram.org');
        }
        return self::row('Telegram', 'ok',
            'Бот @' . ($r['username'] ?? '?') . ' («' . ($r['first_name'] ?? '') . '») відповідає');
    }

    private static function viber(string $token): array
    {
        if ($token === '') return self::row('Viber', 'off', 'Токен не вказано — вхід і сповіщення через Viber вимкнені');
        Viber::useToken($token);
        try {
            $info = Viber::api('get_account_info', []);
        } finally {
            Viber::useToken(null);
        }
        if (($info['status'] ?? -1) !== 0) {
            return self::row('Viber', 'bad',
                'Viber не прийняв токен: ' . ($info['status_message'] ?? 'немає відповіді'),
                'Токен беруть у кабінеті Viber-бота, розділ Edit Info');
        }
        $uri = (string)($info['uri'] ?? '');
        $hook = (string)($info['webhook'] ?? '');
        $mine = rtrim(BotAuth::siteUrl(), '/') . base_url('/api/viber/webhook');
        $text = 'Бот «' . ($info['name'] ?? '?') . '», адреса ' . ($uri !== '' ? 'viber://pa?chatURI=' . $uri : 'невідома');

        // Найважливіше в цій перевірці: у бота лише ОДИН webhook. Якщо він указує
        // на інший сайт — цей бот зараз працює там, і збереження токена тут його
        // забере. Краще, щоб адмін дізнався про це заздалегідь, а не з наслідків.
        if ($hook === '') {
            return self::row('Viber', 'warn', $text, 'Webhook не встановлено — бот поки нічого нам не надсилає. Збережіть налаштування, щоб зареєструвати його.');
        }
        if (BotAuth::siteUrl() !== '' && rtrim($hook, '/') !== rtrim($mine, '/')) {
            return self::row('Viber', 'warn', $text,
                'Webhook бота вказує на ' . $hook . ' — тобто зараз він обслуговує інший сайт. '
                . 'Збереження токена тут перепише адресу, і там бот перестане працювати. '
                . 'Якщо той сайт потрібен — заведіть окремого бота.');
        }
        return self::row('Viber', 'ok', $text . ', webhook наш');
    }

    private static function novaPoshta(string $key): array
    {
        if ($key === '') return self::row('Нова Пошта', 'off', 'Ключа немає — у checkout не буде підказок міст і відділень');
        $ch = curl_init('https://api.novaposhta.ua/v2.0/json/');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'apiKey' => $key, 'modelName' => 'Address', 'calledMethod' => 'searchSettlements',
                'methodProperties' => ['CityName' => 'Київ', 'Limit' => '1'],
            ], JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
        ]);
        $resp = json_decode((string)curl_exec($ch), true);
        $err = curl_error($ch);
        curl_close($ch);

        if (!is_array($resp)) return self::row('Нова Пошта', 'bad', 'Немає відповіді від API' . ($err ? ': ' . $err : ''));
        if (empty($resp['success'])) {
            return self::row('Нова Пошта', 'bad',
                'Ключ відхилено: ' . implode('; ', (array)($resp['errors'] ?? ['без пояснення'])));
        }
        $found = $resp['data'][0]['Addresses'][0]['Present'] ?? '';
        return self::row('Нова Пошта', 'ok', 'Ключ робочий' . ($found !== '' ? ' — тестовий пошук знайшов «' . $found . '»' : ''));
    }

    /**
     * Чи можна створювати накладні. Робочий ключ цього ще не означає: ключ
     * відкриває довідники, а накладну підписує контрагент-відправник, якого
     * треба обрати окремо. Без цієї перевірки перша ж спроба створити ТТН
     * закінчилась би відмовою НП тоді, коли продавець стоїть із коробкою.
     *
     * Самих накладних не створюємо — вони справжні й коштують грошей. Тому
     * перевіряємо заповненість і кажемо прямо, чого це не доводить.
     */
    private static function npSender(string $key): array
    {
        if ($key === '') return [];   // без ключа нема про що говорити — скаже перевірка вище

        $gaps = [];
        if ((string)Settings::get('np_sender_ref', '') === '') $gaps[] = 'контрагент-відправник';
        if ((string)Settings::get('np_sender_contact_ref', '') === '') $gaps[] = 'контактна особа';
        if (NovaPoshta::phone((string)Settings::get('np_sender_phone', '')) === '') $gaps[] = 'телефон відправника';
        if ((string)Settings::get('np_sender_city_ref', '') === '') $gaps[] = 'місто відправлення';
        if ((string)Settings::get('np_sender_warehouse_ref', '') === '') $gaps[] = 'відділення відправлення';

        if ($gaps) {
            return self::row('НП: відправник', 'warn',
                'Накладні поки не створюються — не заповнено: ' . implode(', ', $gaps),
                'Підказки міст і відділень у checkout працюють і без цього. А от кнопка «Створити накладну» '
                . 'у замовленні скаже те саме, що й тут. Заповнюється в картці «Нова Пошта: відправник» нижче.');
        }
        return self::row('НП: відправник', 'ok',
            'Відправник заповнений: ' . (Settings::get('np_sender_name', '') ?: 'контрагент обрано')
            . ', ' . (Settings::get('np_sender_warehouse', '') ?: 'відділення обрано'),
            'Це перевірка заповненості, а не дійсності: чи прийме НП саме цього відправника, '
            . 'з’ясується на першій справжній накладній. Тестових ми не створюємо — вони коштують грошей.');
    }

    /**
     * Каса «Вчасно»: чи прийме вона чек.
     *
     * Питаємо статус ПРРО (task 18) — єдиний запит, який нічого не проводить.
     * Пробний чек тут був би злочином проти обліку: він справжній, іде в ДПС,
     * і скасувати його можна лише чеком повернення.
     *
     * Робочий токен ще не означає, що чек пробʼється: підписує його ключ
     * касира зі сховища кабінету, і саме його відсутність — найчастіша
     * причина «токен же правильний, а нічого не працює».
     */
    private static function vchasno(string $token): array
    {
        $storeCases = DB::all("SELECT name FROM stores WHERE vchasno_token IS NOT NULL AND vchasno_token <> ''");
        $own = $storeCases ? ' Точки з власною касою (' . count($storeCases) . ') перевіряються окремо — '
             . 'цей запит їх не стосується.' : '';

        if ($token === '') {
            // Токен потрібен лише хмарному маршруту. Там, де ключ лежить у
            // магазині, його немає й не має бути — і сказати «токена немає,
            // чеки не пробиваються» означало б злякати на рівному місці.
            $others = DB::all("SELECT name, fiscal_route FROM stores
                               WHERE active = 1 AND fiscal_route IS NOT NULL AND fiscal_route <> 'cloud'
                                 AND fiscal_route <> ''");
            if ($others) {
                return self::row('Каса (ПРРО)', 'ok',
                    'Точки працюють без хмари: '
                    . implode(', ', array_map(fn($s) => $s['name'] . ' — '
                        . mb_strtolower(FiscalProvider::routeLabel((string)$s['fiscal_route'])), $others)),
                    'Ключ підпису лежить у магазині, і токен тут не потрібен. Стан таких кас '
                    . 'показує сторінка «Каса (ПРРО)»: чи виходив на звʼязок агент і що вийшло '
                    . 'з останніх завдань.' . $own);
            }
            return self::row('Каса (ПРРО)', $storeCases ? 'warn' : 'off',
                $storeCases
                    ? 'Загального токена немає, але власні каси є: ' . implode(', ', array_map(fn($s) => (string)$s['name'], $storeCases))
                    : 'Каси не налаштовано — фіскальні чеки не пробиваються',
                $storeCases
                    ? 'Магазини без власного токена чеків не пробиватимуть.'
                    : 'Оберіть маршрут у Налаштуваннях. Для хмари токен беруть у кабінеті ПРРО; '
                      . 'для каси в магазині токен не потрібен — там ключ лежить у Device Manager.');
        }

        Vchasno::useToken($token);
        try {
            $r = Vchasno::status();
        } finally {
            Vchasno::useToken(null);
        }
        if (!$r['ok']) {
            return self::row('Каса (ПРРО)', 'bad', 'Каса не відповіла: ' . $r['error'],
                'Токен беруть у кабінеті каси (Дії з касою → Налаштування каси → Токен) і вставляють повністю. '
                . 'Він показується один раз — якщо загубився, згенеруйте новий.' . $own);
        }

        $info = (array)($r['data']['info'] ?? []);
        $shift = (int)($info['shift_status'] ?? -1);
        $shiftText = match ($shift) {
            Vchasno::SHIFT_OPEN => 'зміна відкрита',
            Vchasno::SHIFT_CLOSED => 'зміна закрита (відкриється сама з першим чеком)',
            default => 'змін ще не було',
        };
        $text = 'Каса ' . ($info['fisid'] ?? '?') . ' (ЄДРПОУ ' . ($info['edrpou'] ?? '?') . '), ' . $shiftText;

        // Тестова каса пробиває чеки без юридичної сили. Це не помилка
        // налаштування — так і перевіряють інтеграцію, — але дізнатись про це
        // з першого податкового періоду було б дорого.
        if (isset($info['isFis']) && (int)$info['isFis'] === 0) {
            return self::row('Каса (ПРРО)', 'warn', $text,
                'Це ТЕСТОВА каса: чеки з неї не мають юридичної сили й у ДПС не потрапляють. '
                . 'Для роботи підставте токен фіскальної каси.' . $own);
        }
        if (isset($info['sign_status']) && (int)$info['sign_status'] < 1) {
            return self::row('Каса (ПРРО)', 'warn', $text,
                'Ключ касира не завантажено у сховище кабінету — чек не буде кому підписати. '
                . 'Кабінет → вкладка «Ключі».' . $own);
        }
        return self::row('Каса (ПРРО)', 'ok', $text,
            'Це відповідь самої каси, а не проведений чек: пробного ми не робимо — він справжній, '
            . 'іде в ДПС і скасовується лише поверненням.' . $own);
    }

    private static function google(array $v): array
    {
        $id = trim((string)($v['google_client_id'] ?? ''));
        $secret = trim((string)($v['google_client_secret'] ?? ''));
        if ($id === '' && $secret === '') return self::row('Google OAuth', 'off', 'Ключів немає — кнопки «Увійти через Google» не буде');
        if ($id === '' || $secret === '') return self::row('Google OAuth', 'bad', 'Заповнено лише одне з двох полів — потрібні обидва');
        if (!str_contains($id, '.apps.googleusercontent.com')) {
            return self::row('Google OAuth', 'warn', 'Client ID не схожий на гугловий',
                'Зазвичай він закінчується на .apps.googleusercontent.com');
        }
        return self::row('Google OAuth', 'ok', 'Ключі на місці',
            'Перевірити насправді можна лише входом: Google приймає ключі тільки в живому обміні. '
            . 'Redirect URI має точно збігатися з ' . GoogleAuth::redirectUri());
    }

    /**
     * Оплата карткою.
     *
     * Живого запиту тут немає й бути не може: шлюз не має читального методу —
     * будь-яке звернення до нього це або платіж, або службова дія над чужим
     * платежем. Тому перевіряємо єдине, що можна перевірити не витрачаючи
     * чиїхось грошей: чи сходиться комплект ключів і чи вміє наш PHP ними
     * підписати. Саме тут ловляться дві найчастіші помилки підключення —
     * зіпсований при копіюванні PEM і сертифікат від іншого середовища.
     */
    private static function acquiring(array $v): array
    {
        $name = 'Оплата карткою';
        $gaps = Acquiring::missing($v);
        if (!Settings::bool('acq_enabled') && $gaps) {
            return self::row($name, 'off', 'Не налаштовано — покупці не бачать оплати карткою');
        }
        if ($gaps) return self::row($name, 'bad', implode('; ', $gaps));

        // Ключ є й читається — лишається довести, що ним справді підписується.
        // Порожній підпис тут означає зазвичай не «ключ поганий», а що openssl
        // не знайшов свого конфіга, — і ця відмова інакше вилізла б на першій
        // живій оплаті.
        $probe = Acquiring::sign('bofu-selftest');
        if ($probe === '') {
            return self::row($name, 'bad', 'Ключ прийнявся, але підпис не формується',
                'Зазвичай це OpenSSL, який не знаходить свій openssl.cnf. Вкажіть шлях у змінній OPENSSL_CONF '
                . '(на Windows: C:\\xampp\\apache\\conf\\openssl.cnf) і перезапустіть Apache.');
        }

        $env = Acquiring::env();
        $note = 'Що банк прийме саме ці реквізити, перевірка не доводить — шлюз не має запиту, '
              . 'який нічого не робить. Це покаже перший платіж на тестовому середовищі. '
              . 'І передайте банку адреси NOTIFY_URL та SUCCESS_URL — без NOTIFY_URL оплати '
              . 'не позначатимуться автоматично.';
        if (!Settings::bool('acq_enabled')) {
            return self::row($name, 'warn', 'Усе на місці, але приймання оплат вимкнене галкою', $note);
        }
        return self::row($name, $env === 'test' ? 'warn' : 'ok',
            'Ключі сходяться, підпис формується. Середовище: ' . ($env === 'test' ? 'тестове' : 'робоче')
            . ', Merchant ' . Acquiring::merchantId() . ' / Terminal ' . Acquiring::terminalId(),
            $env === 'test' ? 'Тестовий шлюз справжніх грошей не приймає — перемкніть на робоче, коли перевірите.' : $note);
    }

    /**
     * Пошта. Перевіряє не «чи доходять листи» (тестовий лист пішов би живій
     * людині), а те, що видно без відправки: чи адреси взагалі адреси, з якого
     * домену вони й куди піде відповідь.
     *
     * Головне, заради чого це написано, — попередження про чужий домен. Вписати
     * тут власний gmail здається найпростішим рішенням, а насправді це найгірше:
     * gmail.com каже приймачам «листи від нас ідуть лише з наших серверів», наш
     * хостинг ним не є, і лист із кодом входу відхиляють ще до теки «Спам».
     */
    private static function email(array $v): array
    {
        $host = Notify::mailHost();
        $fields = [
            'mail_from'      => 'Відправник',
            'mail_from_auth' => 'Коди входу',
            'mail_reply_to'  => 'Відповіді',
        ];
        $vals = [];
        foreach ($fields as $key => $label) {
            $raw = trim((string)($v[$key] ?? ''));
            if ($raw !== '' && Notify::cleanAddress($raw) === '') {
                return self::row('Email', 'bad', '«' . $raw . '» не схоже на адресу (' . mb_strtolower($label) . ')');
            }
            $vals[$key] = Notify::cleanAddress($raw);
        }

        // Те, що справді стане у From, — з тими самими замовчуваннями, що й у листі
        $from = $vals['mail_from'] !== '' ? $vals['mail_from'] : Notify::DEFAULT_USER . '@' . $host;
        $auth = $vals['mail_from_auth'] !== '' ? $vals['mail_from_auth']
              : ($vals['mail_from'] !== '' ? $vals['mail_from'] : Notify::DEFAULT_AUTH_USER . '@' . $host);
        $reply = $vals['mail_reply_to'] !== '' ? $vals['mail_reply_to'] : $vals['mail_from'];

        $text = 'Листи: ' . $from . ', коди входу: ' . $auth
              . ($reply !== '' && strcasecmp($reply, $from) !== 0 ? ', відповіді: ' . $reply : '');

        // Попередження тримаємо окремо від пояснення в кінці: інакше «є що
        // сказати» й «щось не так» злиплися б в одне, і жовтий знак горів би завжди
        $warn = [];
        if ($vals['mail_from'] === '') {
            $warn[] = 'Адресу не вказано — беремо ' . $from . '. Якщо такої скриньки немає, відповіді покупців і '
                     . 'повідомлення про недоставлені листи зникнуть безслідно.';
        }
        $replyLives = $reply !== '' && strcasecmp($reply, $from) !== 0;
        foreach (array_unique(['листів' => $from, 'кодів входу' => $auth]) as $what => $addr) {
            $dom = strtolower(substr(strrchr($addr, '@') ?: '', 1));
            if ($dom !== '' && $host !== 'localhost' && $dom !== $host && !str_ends_with($dom, '.' . $host)) {
                $warn[] = 'Адреса ' . $what . ' — на чужому домені (' . $dom . '). Поштові служби перевіряють, '
                         . 'чи має цей сервер право слати від імені ' . $dom . ', і майже завжди відповідь «ні»: '
                         . 'листи підуть у «Спам» або будуть відхилені. Заведіть скриньку на ' . $host . '.';
            }
            // noreply з живим Reply-To — нормально: відповідь усе одно дійде
            if (!$replyLives && preg_match('~^(noreply|no-reply|donotreply)@~i', $addr)) {
                $warn[] = 'Адреса ' . $what . ' — noreply. Покупці відповідають на листи про замовлення, '
                         . 'і ці відповіді зникають. Або жива скринька у From, або заповніть Reply-To.';
            }
        }
        $always = 'Що листи справді доходять, це не доводить — тестового листа не шлемо, бо він пішов би живій людині. '
                 . 'Перевірте оформленням замовлення на власну адресу й входом за кодом. '
                 . 'І пропишіть у DNS домену SPF, DKIM і DMARC: без них частина листів осідає у «Спамі» незалежно від адреси.';

        return self::row('Email', $warn ? 'warn' : 'ok', $text, trim(implode(' ', $warn) . ' ' . $always));
    }
    private static function botSite(string $url): array
    {
        $eff = $url !== '' ? rtrim($url, '/') : BotAuth::siteUrl();
        if ($eff === '') return self::row('Адреса сайту для бота', 'bad', 'Не вказано й не визначилась — кнопка «повернутись на сайт» у боті нікуди не веде');
        if (!preg_match('~^https?://~', $eff)) return self::row('Адреса сайту для бота', 'bad', '«' . $eff . '» — адреса має починатися з http:// або https://');
        if ($url === '') {
            return self::row('Адреса сайту для бота', 'warn', 'Визначилась сама: ' . $eff,
                'Viber стукає у webhook власним запитом, тож на бойовому сервері краще вписати адресу явно');
        }
        return self::row('Адреса сайту для бота', 'ok', $eff);
    }

    private static function push(): array
    {
        [$pub] = WebPush::ensureKeys();
        if (!$pub) {
            return self::row('Web Push', 'bad', 'Ключі не згенерувалися',
                extension_loaded('openssl')
                    ? 'OpenSSL є, але не зміг створити ключ — зазвичай він не знаходить свій openssl.cnf. Вкажіть шлях у змінній OPENSSL_CONF (на Windows: C:\\xampp\\apache\\conf\\openssl.cnf) і перезапустіть Apache.'
                    : 'Розширення OpenSSL вимкнене в php.ini — увімкніть extension=openssl');
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
              || in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1'], true)
              || str_starts_with($_SERVER['HTTP_HOST'] ?? '', 'localhost:');
        if (!$https) return self::row('Web Push', 'warn', 'Ключі є, але сайт відкрито не через HTTPS', 'Пуші в браузері працюють лише на HTTPS (або localhost)');
        return self::row('Web Push', 'ok', 'Ключі є, протокол підходить');
    }
}
