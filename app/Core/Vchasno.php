<?php
declare(strict_types=1);

/**
 * Вчасно.Каса: єдина точка розмови з kasa.vchasno.ua.
 *
 * Тут тільки транспорт — рішень про чеки цей клас не ухвалює. Що саме
 * фіскалізувати, коли й від імені якого магазину, вирішує Fiscal; так помилку
 * в нашій логіці видно окремо від помилки ПРРО, а тести можуть підмінити
 * транспорт, не чіпаючи решти (той самий поділ, що в NovaPoshta / Shipments).
 *
 * Усі фіскальні завдання йдуть одним POST /api/v3/fiscal/execute і різняться
 * лише номером завдання (task). Відповідь завжди має однакову форму: res = 0
 * означає «зроблено», res_action = 3 — «дивись помилку», решта полів лежить в
 * info. Тому кожен метод повертає ['ok','data','error','res'] і не кидає
 * винятків: недоступний ПРРО — буденність (немає світла, ДПС на профілактиці),
 * а не поломка сайту.
 *
 * ТОКЕН НАЛЕЖИТЬ КАСІ, а не сайту. У кожної торгової точки своя каса зі своїм
 * токеном, і чек мусить пробитись саме на тій, де стоїть покупець: фіскальний
 * номер чека, зміна й Z-звіт належать конкретному ПРРО. Тому всі методи
 * приймають id магазину, а загальний токен із налаштувань — це запасний
 * варіант для магазину, у якого власного не вказано (одна каса на все).
 */
class Vchasno
{
    public const API = 'https://kasa.vchasno.ua';

    /** Фіскальні завдання. Ті, якими користуємось; повний перелік — у їхній документації. */
    public const TASK_SHIFT_OPEN = 0;
    public const TASK_SELL       = 1;
    public const TASK_RETURN     = 2;
    public const TASK_CASH_IN    = 3;
    public const TASK_CASH_OUT   = 4;
    public const TASK_X_REPORT   = 10;
    public const TASK_Z_REPORT   = 11;
    public const TASK_STATUS     = 18;

    /**
     * Податкові групи ДПС — коди їхні, підписи наші.
     *
     * Ставка друкується в чеку й іде в податкову, тож вибір із назвами
     * замість числа тут не прикраса: «2» в порожньому полі не читається ніяк,
     * а помилка виявиться в перший же податковий період.
     */
    public const TAX_GROUPS = [
        1 => 'ПДВ 20%',
        2 => 'Без ПДВ',
        3 => 'ПДВ 20% + акциз 5%',
        4 => 'ПДВ 7%',
        5 => 'ПДВ 0%',
        6 => 'Без ПДВ + акциз 5%',
        7 => 'Не є об’єктом ПДВ',
        8 => 'ПДВ 20% + ПФ 7.5%',
        9 => 'ПДВ 14%',
    ];

    /**
     * Види оплати. У API їх понад двадцять (LiqPay, Portmone, NovaPay…), але
     * тут лише ті, якими розраховуються в живій точці. Зайвий пункт у списку
     * продавця — це зайва нагода обрати не те під час черги.
     */
    public const PAY_TYPES = [
        0 => 'Готівка',
        2 => 'Картка',
        1 => 'Безготівка (рахунок)',
    ];

    /**
     * Стан зміни, як його називає ПРРО (info.shift_status).
     * -1 трапляється на щойно заведеній касі, яка ще не бачила жодної зміни.
     */
    public const SHIFT_CLOSED = 0;
    public const SHIFT_OPEN   = 1;

    /**
     * Підміна HTTP для тестів: замикання отримує ['path','body','token'] і
     * повертає масив відповіді. Живої каси в тестах немає, а перевіряти треба
     * саме те, що ми надсилаємо, — інакше набір або пробивав би справжні чеки
     * (їх не відкотиш), або не перевіряв нічого.
     */
    public static ?Closure $transport = null;

    // ──────────────────────────────────────────────────────────────── доступ

    /**
     * Тимчасовий токен замість збереженого — для перевірки того, що вписали у
     * форму налаштувань, ще до збереження (див. IntegrationCheck). Тим самим
     * прийомом користуються Telegram і Viber, і з тієї самої причини:
     * «перевірити» не має означати «зберегти», інакше помилковий токен
     * лишається в базі.
     */
    private static ?string $override = null;

    public static function useToken(?string $token): void
    {
        self::$override = $token !== null ? trim($token) : null;
    }

    /** Токен каси цього магазину; порожній — магазин працює на загальній касі */
    public static function token(?int $storeId = null): string
    {
        if (self::$override !== null) return self::$override;
        if ($storeId) {
            $own = (string)(DB::val('SELECT vchasno_token FROM stores WHERE id = ?', [$storeId]) ?? '');
            if (trim($own) !== '') return trim($own);
        }
        return trim((string)Settings::get('vchasno_token', ''));
    }

    public static function enabled(?int $storeId = null): bool
    {
        return self::token($storeId) !== '';
    }

    /** Чи є хоч одна налаштована каса — щоб не показувати розділ там, де його не вмикали */
    public static function anyEnabled(): bool
    {
        if (trim((string)Settings::get('vchasno_token', '')) !== '') return true;
        return (bool)DB::val("SELECT 1 FROM stores WHERE vchasno_token IS NOT NULL AND vchasno_token <> '' LIMIT 1");
    }

    /** Посилання на електронний чек — те, що бачить покупець за QR-кодом */
    public static function checkUrl(string $fiscalNumber): string
    {
        return self::API . '/c/' . rawurlencode(trim($fiscalNumber));
    }

    // ─────────────────────────────────────────────────────────────── транспорт

    /**
     * Фіскальне завдання.
     *
     * $extra — поля верхнього рівня поруч із fiscal: tag (наш ідентифікатор
     * запиту), source, userinfo. tag тут головний: за ним ПРРО впізнає
     * повторний запит і віддає той самий чек замість другого. Без нього
     * обірваний зв’язок означав би два чеки на один продаж — і ручне
     * повернення в кабінеті.
     *
     * @return array{ok:bool,data:array,error:string,res:int}
     */
    public static function execute(array $fiscal, array $extra = [], ?int $storeId = null): array
    {
        $body = $extra + ['fiscal' => $fiscal];
        if (!isset($body['source'])) $body['source'] = 'BOFU';
        return self::post('/api/v3/fiscal/execute', $body, $storeId);
    }

    /**
     * Надіслати вже зібране тіло і віддати відповідь як є.
     *
     * Цим ходить Fiscal: тіло йому будує перекладач постачальника (VchasnoDoc),
     * і розбирати відповідь теж має він — інакше знання про формат Вчасно
     * розповзлось би по двох місцях, і при заміні постачальника довелося б
     * шукати друге.
     *
     * Порожній масив означає «відповіді не було»: чек МІГ пробитись, і
     * вирішувати, що з цим робити, — теж не наша справа, а Fiscal.
     */
    public static function exec(array $body, ?int $storeId = null): array
    {
        $r = self::post('/api/v3/fiscal/execute', $body, $storeId);
        if (self::unclear($r)) return [];
        // Помилку рівня HTTP (не прийняли токен) віддаємо у тій самій формі,
        // у якій ПРРО повертає свої відмови: далі її розбирає один код.
        if (!$r['ok'] && !$r['data']) return ['res' => 1, 'errortxt' => $r['error']];
        return $r['data'];
    }

    /** Стан ПРРО: чи відкрита зміна, чи бачить каса ДПС, скільки готівки в скриньці */
    public static function status(?int $storeId = null): array
    {
        return self::execute(['task' => self::TASK_STATUS], [], $storeId);
    }

    public static function openShift(string $cashier, ?int $storeId = null): array
    {
        return self::execute(['task' => self::TASK_SHIFT_OPEN, 'cashier' => self::clean($cashier, 100)], [], $storeId);
    }

    /** X-звіт нічого не закриває — це «скільки набігло відтоді, як відкрились» */
    public static function xReport(string $cashier, ?int $storeId = null): array
    {
        return self::execute(['task' => self::TASK_X_REPORT, 'cashier' => self::clean($cashier, 100)], [], $storeId);
    }

    /** Z-звіт закриває зміну. Закон вимагає робити це щонайменше раз на 24 години. */
    public static function zReport(string $cashier, ?int $storeId = null): array
    {
        return self::execute(['task' => self::TASK_Z_REPORT, 'cashier' => self::clean($cashier, 100)], [], $storeId);
    }

    /** Службове внесення (готівка в скриньку на початку дня) */
    public static function cashIn(float $sum, string $comment, ?int $storeId = null): array
    {
        return self::execute([
            'task' => self::TASK_CASH_IN, 'cashier' => self::cashierName(),
            'cash' => ['type' => 0, 'sum' => self::money($sum), 'comment_down' => self::clean($comment, 120)],
        ], [], $storeId);
    }

    /** Службова видача (інкасація) */
    public static function cashOut(float $sum, string $comment, ?int $storeId = null): array
    {
        return self::execute([
            'task' => self::TASK_CASH_OUT, 'cashier' => self::cashierName(),
            'cash' => ['type' => 0, 'sum' => self::money($sum), 'comment_down' => self::clean($comment, 120)],
        ], [], $storeId);
    }

    /** Зміни цієї каси, найновіші першими */
    public static function shifts(int $page = 1, ?int $storeId = null): array
    {
        return self::get('/api/v3/shifts', ['page_num' => $page, 'page_size' => 50], $storeId);
    }

    /** Чеки однієї зміни */
    public static function shiftChecks(string $shiftId, int $page = 1, ?int $storeId = null): array
    {
        return self::get('/api/v3/shift/checks',
            ['shift_id' => $shiftId, 'page_num' => $page, 'page_size' => 100], $storeId);
    }

    /**
     * Надіслати покупцю посилання на його чек.
     *
     * Окремий запит, а не userinfo в самому чеку: userinfo працює лише в мить
     * фіскалізації, а покупець просить чек і назавтра («загубив, перекиньте»).
     *
     * @param string $channel email|sms|viber
     */
    public static function sendLink(string $fiscalNumber, string $channel, string $recipient, ?int $storeId = null): array
    {
        return self::post('/api/v3/notifications/checks', [
            'check' => trim($fiscalNumber),
            'channel' => in_array($channel, ['email', 'sms', 'viber'], true) ? $channel : 'email',
            'recipient' => trim($recipient),
        ], $storeId);
    }

    // ───────────────────────────────────────────────────────────────── HTTP

    /**
     * @return array{ok:bool,data:array,error:string,res:int}
     */
    private static function post(string $path, array $body, ?int $storeId): array
    {
        $token = self::token($storeId);
        if ($token === '') return self::fail('Немає токена каси «Вчасно.Каса»');

        if (self::$transport) {
            $resp = (self::$transport)(['path' => $path, 'body' => $body, 'token' => $token, 'store_id' => $storeId]);
            // Порожня відповідь означає «каса змовчала» — інакше підмінений
            // транспорт не міг би відтворити найважливіший для нас випадок:
            // обрив звʼязку, після якого невідомо, чи чек пробився.
            if (!is_array($resp) || !$resp) return self::fail('Каса не відповіла', -1);
            return self::unwrap($resp);
        }

        $ch = curl_init(self::API . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: ' . $token],
            CURLOPT_RETURNTRANSFER => true,
            // 25 секунд — рекомендація самої «Вчасно.Каси»: при проблемах з АЦСК
            // чи ДПС вони не відмовляють, а тримають запит і дотискають його.
            // Коротший таймаут перетворив би вдалу фіскалізацію на «мережа
            // впала» — з чеком, який ми вважаємо непроведеним.
            CURLOPT_TIMEOUT => 25,
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return self::handle($path, $raw, $err, $code);
    }

    private static function get(string $path, array $query, ?int $storeId): array
    {
        $token = self::token($storeId);
        if ($token === '') return self::fail('Немає токена каси «Вчасно.Каса»');

        if (self::$transport) {
            $resp = (self::$transport)(['path' => $path, 'query' => $query, 'token' => $token, 'store_id' => $storeId]);
            if (!is_array($resp) || !$resp) return self::fail('Каса не відповіла', -1);
            return self::unwrap($resp);
        }

        $ch = curl_init(self::API . $path . ($query ? '?' . http_build_query($query) : ''));
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: ' . $token],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return self::handle($path, $raw, $err, $code);
    }

    /**
     * Розбір відповіді. Три різні невдачі, і плутати їх не можна:
     * мережа не дійшла (чек міг пробитись!), HTTP відмовив (токен, права),
     * ПРРО відмовив по суті (res != 0) — останнє єдине, що можна виправити
     * у формі.
     */
    private static function handle(string $path, $raw, string $err, int $code): array
    {
        if ($raw === false) {
            self::log("$path: мережа — $err");
            // Навмисно окремий код: Fiscal лишає такий чек у стані «невідомо»
            // й повторює запит із тим самим tag, а не пише «не вийшло».
            return self::fail('Каса не відповідає' . ($err !== '' ? ": $err" : ''), -1);
        }
        $json = json_decode((string)$raw, true);
        if (!is_array($json)) {
            self::log("$path: HTTP $code, не JSON — " . mb_substr((string)$raw, 0, 300));
            if ($code === 401 || $code === 403) return self::fail('Каса не прийняла токен (HTTP ' . $code . ')');
            return self::fail('Каса відповіла незрозумілим (HTTP ' . $code . ')', -1);
        }
        if ($code >= 400) {
            $msg = (string)($json['message'] ?? $json['errortxt'] ?? ('HTTP ' . $code));
            self::log("$path: HTTP $code — $msg");
            return self::fail($code === 401 || $code === 403 ? 'Каса не прийняла токен: ' . $msg : $msg);
        }
        $out = self::unwrap($json);
        if (!$out['ok']) self::log("$path: " . $out['error']);
        return $out;
    }

    /**
     * Відповідь ПРРО в нашу форму.
     *
     * Службові GET-и (зміни, чеки) поля res не мають узагалі — для них сам
     * факт відповіді вже успіх. Тому «немає res» читаємо як 0, а не як помилку.
     */
    private static function unwrap(array $json): array
    {
        $res = array_key_exists('res', $json) ? (int)$json['res'] : 0;
        $ok = $res === 0;
        $error = '';
        if (!$ok) {
            $error = trim((string)($json['errortxt'] ?? ''));
            // error_extra пояснює, ЩО саме не так (яке поле, які числа не
            // зійшлись). Без нього найчастіша відмова читається як загадка:
            // «Сума всіх позицій відрізняється від суми чеку» — а на скільки?
            $extra = $json['error_extra'] ?? null;
            if (is_array($extra) && $extra) {
                $bits = [];
                foreach ($extra as $k => $v) $bits[] = $k . ': ' . (is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE));
                $error .= ($error !== '' ? ' (' : '(') . implode(', ', $bits) . ')';
            }
            if ($error === '') $error = 'Каса відмовила без пояснення (код ' . $res . ')';
        }
        return ['ok' => $ok, 'data' => $json, 'error' => $error, 'res' => $res];
    }

    private static function fail(string $error, int $res = -2): array
    {
        return ['ok' => false, 'data' => [], 'error' => $error, 'res' => $res];
    }

    /** Відмова через мережу: чек міг і пробитись, тож повторювати треба тим самим tag */
    public static function unclear(array $r): bool
    {
        return !$r['ok'] && (int)($r['res'] ?? 0) === -1;
    }

    // ────────────────────────────────────────────────────────────── дрібниці

    /**
     * Текст, який ПРРО погодиться надрукувати.
     *
     * Дозволена абетка в них своя й доволі вузька: латиниця, кирилиця, цифри
     * і жменя розділових знаків. Усе інше (емодзі в назві товару, стрілки,
     * нерозривні пробіли з копіпасти) валить увесь чек з валідаційною
     * помилкою — тому чистимо мовчки, а не з’ясовуємо стосунки над черзею.
     */
    public static function clean(string $text, int $max = 255): string
    {
        $ok = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz'
            . 'АБВГДЕЁЖЗИЙКЛМНОПРСТУФХЦЧШЩЪЫЬЭЮЯабвгдеёжзийклмнопрстуфхцчшщъыьэюя'
            . 'ІіЇїҐґЄє !.,"№;:?\\*()|/@#$%^-_+=~\'&{}[]®©«»°±‘’“”–•—™„‰‹› 0123456789';
        $allowed = [];
        foreach (preg_split('//u', $ok, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) $allowed[$ch] = true;

        $out = '';
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
            $out .= isset($allowed[$ch]) ? $ch : ' ';
        }
        $out = trim(preg_replace('/\s+/u', ' ', $out) ?? '');
        return mb_substr($out, 0, $max);
    }

    /** Гроші так, як їх чекає ПРРО: рівно два знаки, без «плаваючих» хвостів */
    public static function money(float $sum): float
    {
        return round($sum, 2);
    }

    /** Ім’я касира в чеку: своє з налаштувань, інакше назва сайту */
    public static function cashierName(?string $name = null): string
    {
        $name = trim((string)($name ?? ''));
        if ($name === '') $name = trim((string)Settings::get('vchasno_cashier', ''));
        if ($name === '') $name = (string)cfg('app_name', 'Магазин');
        return self::clean($name, 100);
    }

    /** Дата й час у форматі завдання: YYYYMMDDHHMMSS */
    public static function dt(?string $when = null): string
    {
        return date('YmdHis', $when !== null ? (strtotime($when) ?: time()) : time());
    }

    public static function log(string $msg): void
    {
        $dir = BOFU_ROOT . '/storage/logs';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        @file_put_contents($dir . '/vchasno.log', now() . ' ' . $msg . "\n", FILE_APPEND);
    }
}
