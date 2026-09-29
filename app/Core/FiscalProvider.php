<?php
declare(strict_types=1);

/**
 * Хто саме пробиває наші чеки і як ми до нього доходимо.
 *
 * Постачальник ПРРО — це послуга, яку міняють: дорожчає, псується підтримка,
 * з’являється зручніший. Тому логіка чека (що в ньому рядків, як розкладена
 * знижка, яка податкова група, коли пробивати) не знає про Вчасно нічого:
 * Fiscal складає НЕЙТРАЛЬНИЙ документ, а перекладає його на мову конкретного
 * ПРРО окремий клас. Щоб додати Checkbox чи Cashalot, треба написати такий
 * клас і дописати рядок сюди — решта коду не змінюється.
 *
 * Постачальник записується в САМ ЧЕК (fiscal_receipts.provider), а не читається
 * з налаштувань при потребі. Це не надмірність: повернення робить той самий
 * ПРРО, що й продаж, — чек, пробитий у Вчасно, не повернеш у Checkbox. Тож
 * після переходу старі чеки далі знають свого постачальника й лишаються
 * робочими, а нові йдуть до нового. Перехід не має бути подією, до якої
 * готуються місяць.
 *
 * МАРШРУТ — окреме питання від постачальника: це «як ми доходимо до каси».
 *
 *   cloud  — наш сервер говорить з хмарою постачальника. Ключ підпису лежить
 *            у нього; найпростіше в налаштуванні й найгірше для того, хто не
 *            хоче віддавати ключ.
 *   agent  — каса стоїть на ПК точки, ключ там же (флешка чи папка), а завдання
 *            їй приносить агент, який САМ стукає до нас. Назовні не відкрито
 *            нічого; телефон продавця працює звідусіль, бо він говорить лише
 *            з нашим сайтом.
 *   device — каса на тому самому пристрої, де працює продавець, і його браузер
 *            звертається на localhost. Для ПК і для телефона з Device Manager.
 *
 * Маршрут вибирається в три яруси: людина → її точка → загальне налаштування.
 * Продавець за касовим ПК ставить собі «на цьому пристрої», продавець із
 * телефоном у залі лишає маршрут точки, і жоден із них більше про це не думає.
 */
class FiscalProvider
{
    /**
     * Постачальники ПРРО.
     *
     * 'doc' — клас, що вміє перекласти нейтральний документ у запит цього
     * постачальника й розібрати його відповідь. Контракт: статичні
     * body(array $doc, array $route): array і parse(array $resp): array.
     */
    public const PROVIDERS = [
        // ПРРО ДПС: чек підписує КЕП касира в браузері (ключ із флешки чи
        // телефона), сервер складає документ і пересилає на fs.tax.gov.ua —
        // див. DpsDoc, DpsFlow і assets/js/dps-sign.js
        'dps' => [
            'label' => 'ПРРО ДПС (webrro.tax.gov.ua)',
            'doc' => 'DpsDoc',
            'routes' => ['device'],
            'cabinet' => 'https://webrro.tax.gov.ua/',
        ],
        'vchasno' => [
            'label' => 'Вчасно.Каса',
            'doc' => 'VchasnoDoc',
            'routes' => ['cloud', 'agent', 'device'],
            'cabinet' => 'https://kasa.vchasno.ua/',
        ],
    ];

    public const ROUTES = [
        'cloud'  => 'Хмара постачальника',
        'agent'  => 'Каса точки (агент на ПК магазину)',
        'device' => 'Каса на цьому пристрої',
    ];

    /** Куди звертається Device Manager за замовчуванням */
    public const DM_DEFAULT_URL = 'http://localhost:3939';

    public static function label(string $provider): string
    {
        return self::PROVIDERS[$provider]['label'] ?? $provider;
    }

    public static function routeLabel(string $route): string
    {
        return self::ROUTES[$route] ?? $route;
    }

    /** Клас-перекладач постачальника; порожньо — постачальника не знаємо */
    public static function docClass(string $provider): string
    {
        return (string)(self::PROVIDERS[$provider]['doc'] ?? '');
    }

    public static function current(): string
    {
        $p = (string)Settings::get('fiscal_provider', 'dps');
        return isset(self::PROVIDERS[$p]) ? $p : 'dps';
    }

    /**
     * Як цей продавець у цій точці доходить до каси.
     *
     * Три яруси, від найближчого до людини. Порожнє поле означає «як ярусом
     * вище», а не «ніяк»: інакше кожну нову точку довелося б налаштовувати
     * цілком, аби вона просто працювала як усі.
     *
     * @return array{provider:string,class:string,route:string,url:string,device:string,token:string,source:string,label:string}
     */
    public static function route(?int $storeId, ?int $userId = null): array
    {
        $provider = self::current();
        $out = [
            'provider' => $provider,
            'class' => self::docClass($provider),
            'route' => (string)Settings::get('fiscal_route', 'cloud'),
            'url' => '', 'device' => '',
            'token' => '',
            'source' => 'global',
        ];
        if (!isset(self::ROUTES[$out['route']])) $out['route'] = 'cloud';

        $store = $storeId ? DB::row('SELECT * FROM stores WHERE id = ?', [$storeId]) : null;
        if ($store && trim((string)($store['fiscal_route'] ?? '')) !== '') {
            $out['route'] = (string)$store['fiscal_route'];
            $out['url'] = trim((string)($store['dm_url'] ?? ''));
            $out['device'] = trim((string)($store['dm_device'] ?? ''));
            $out['source'] = 'store';
        }

        // Власна каса людини перекриває точку: у неї свій ПРРО зі своїм ключем,
        // і чек має піти саме туди, хоч би де вона зараз стояла.
        $user = $userId ? DB::row('SELECT * FROM users WHERE id = ?', [$userId]) : null;
        if ($user && trim((string)($user['fiscal_route'] ?? '')) !== '') {
            $out['route'] = (string)$user['fiscal_route'];
            $out['url'] = trim((string)($user['dm_url'] ?? ''));
            $out['device'] = trim((string)($user['dm_device'] ?? ''));
            $out['source'] = 'user';
        }

        if (!isset(self::ROUTES[$out['route']])) $out['route'] = 'cloud';
        // Постачальник уміє не всі маршрути: у ДПС хмари з нашим ключем немає,
        // підписує лише браузер продавця — тож маршрут для нього завжди «на пристрої»
        $allowed = self::PROVIDERS[$provider]['routes'] ?? [];
        if ($allowed && !in_array($out['route'], $allowed, true)) $out['route'] = $allowed[0];
        $out['store_id'] = $storeId;
        if ($provider === 'dps') {
            $out['label'] = self::label($provider) . ' · підпис ключем на пристрої касира';
            return $out;
        }
        if ($out['route'] === 'cloud') {
            $out['token'] = Vchasno::token($storeId);
            $out['url'] = Vchasno::API;
        } elseif ($out['url'] === '') {
            $out['url'] = self::DM_DEFAULT_URL;
        }
        $out['label'] = self::label($provider) . ' · ' . self::routeLabel($out['route']);
        return $out;
    }

    /**
     * Чи готовий цей маршрут пробити чек. Список перешкод, а не «так/ні»:
     * продавцю треба знати, що саме заповнити й чи це взагалі його робота.
     *
     * @return string[]
     */
    public static function missing(array $route): array
    {
        $out = [];
        if ($route['class'] === '' || !class_exists($route['class'])) {
            return ['постачальника «' . $route['provider'] . '» система не знає'];
        }
        if ($route['provider'] === 'dps') {
            $store = !empty($route['store_id']) ? DB::row('SELECT * FROM stores WHERE id = ?', [(int)$route['store_id']]) : null;
            return DpsDoc::ctx($store)['missing'];
        }
        if ($route['route'] === 'cloud') {
            if ($route['token'] === '') $out[] = 'немає токена каси (Налаштування або картка точки)';
            return $out;
        }
        // agent і device: без назви ПРРО в Device Manager запит нікуди не
        // адресований — DM визначає касу саме нею, а не адресою.
        if ($route['device'] === '') {
            $out[] = $route['route'] === 'device'
                ? 'у вашому профілі не вказано назву каси в Device Manager'
                : 'у точці не вказано назву каси в Device Manager';
        }
        if ($route['url'] === '') $out[] = 'не вказано адресу Device Manager';
        return $out;
    }

    /** Чи налаштована бодай одна каса — щоб не показувати розділів, які вміють лише відмовляти */
    public static function anyConfigured(): bool
    {
        if (Vchasno::anyEnabled()) return true;
        if (self::current() === 'dps') {
            return (bool)DB::val("SELECT 1 FROM stores WHERE dps_fiscal_num IS NOT NULL AND dps_fiscal_num <> '' LIMIT 1");
        }
        if ((string)Settings::get('fiscal_route', 'cloud') !== 'cloud') return true;
        if (DB::val("SELECT 1 FROM stores WHERE fiscal_route IS NOT NULL AND fiscal_route <> '' LIMIT 1")) return true;
        return (bool)DB::val("SELECT 1 FROM users WHERE fiscal_route IS NOT NULL AND fiscal_route <> '' LIMIT 1");
    }

    /**
     * Адреса Device Manager, звужена до того, чим вона є насправді.
     *
     * DM — це служба, що стоїть на ТОМУ Ж комп'ютері, де працює продавець, і
     * слухає вона лише localhost: ключ ПРРО лежить у магазині й назовні не
     * виходить. Тобто «будь-яка http-адреса» тут ніколи не була потрібна — це
     * була просто надто широка форма поля.
     *
     * Широка форма коштує двох речей. Перша: адреса потрапляє в завдання, яке
     * отримує вкладка продавця, а в тіло завдання йде чек — тобто поле
     * дозволяло тихо відвести чеки на сторонній сервер. Друга, важливіша: поле,
     * у яке можна вписати будь-що, рано чи пізно заповнюють будь-чим, і
     * розбиратись доводиться посеред черги біля каси.
     *
     * Тому приймаємо лише localhost, 127.0.0.1 і ::1 — з портом і без.
     * Повертаємо null, якщо адреса не така: для виклику це означає «діє
     * типова адреса», а не «зламано».
     */
    public static function normalizeDmUrl(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') return null;
        if (!preg_match('~^https?://~i', $raw)) return null;

        $host = strtolower((string)parse_url($raw, PHP_URL_HOST));
        if (!in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true)) return null;

        return mb_substr(rtrim($raw, '/'), 0, 200);
    }

    // ─────────────────────────────────────────────────────────── токен агента

    /**
     * Новий токен для агента точки.
     *
     * Повертаємо відкритий токен рівно один раз — його треба вписати в агента.
     * У базі лишається хеш: перелік токенів усіх кас мережі надто дорогий, щоб
     * зберігати його у відкритому вигляді, а перевірити збіг хеша достатньо.
     */
    public static function newAgentToken(int $storeId): string
    {
        $token = 'ag_' . bin2hex(random_bytes(24));
        DB::update('stores', ['agent_hash' => hash('sha256', $token)], 'id = ?', [$storeId]);
        return $token;
    }

    /** Чия це точка за токеном агента; null — токен не наш */
    public static function storeByAgentToken(string $token): ?array
    {
        $token = trim($token);
        if ($token === '') return null;
        // Порівнюємо хеші, а не токени: так у запиті до бази немає нічого
        // секретного, і рядок агента не спливе у повільному лозі запитів.
        return DB::row("SELECT * FROM stores WHERE agent_hash = ? AND active = 1",
                       [hash('sha256', $token)]);
    }
}
