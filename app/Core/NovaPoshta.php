<?php
declare(strict_types=1);

/**
 * Нова Пошта: єдина точка розмови з api.novaposhta.ua.
 *
 * Тут тільки транспорт і довідники — рішень про замовлення цей клас не ухвалює.
 * Що саме відправляємо, кому і коли записати подію, вирішує Shipments; так
 * помилку в нашій логіці видно окремо від помилки в чужому API, а тести можуть
 * підмінити транспорт, не чіпаючи решти.
 *
 * Кожен метод повертає однакову форму ['ok', 'data', 'error'] і не кидає
 * винятків. Недоступна Нова Пошта — це буденність, а не поломка сайту:
 * продавцю треба показати причину й лишити кнопку, а не сторінку 500.
 */
class NovaPoshta
{
    public const API = 'https://api.novaposhta.ua/v2.0/json/';

    /** Куди веде покупця посилання «відстежити» */
    public const TRACK_URL = 'https://novaposhta.ua/tracking/?cargo_number=';

    /**
     * Підміна HTTP для тестів: замикання отримує масив запиту й повертає масив
     * відповіді. Живого ключа в тестах немає, а перевіряти треба саме те, що ми
     * надсилаємо, — інакше набір або ходив би в мережу, або не перевіряв нічого.
     */
    public static ?Closure $transport = null;

    /**
     * Статуси Нової Пошти. Код сталий, а текст НП міняє й доповнює датами, тому
     * свій підпис коротший і не залежить від їхнього формулювання.
     *
     * Друге значення — те, що з цього випливає для нас:
     *   new      — накладна створена, посилку ще не передали
     *   transit  — їде
     *   arrived  — чекає на отримувача у відділенні
     *   done     — отримано
     *   problem  — відмова, повернення, припинено зберігання
     *   gone     — накладної більше немає (видалена або не знайдена)
     */
    public const STATUSES = [
        1   => ['Створено накладну', 'new'],
        2   => ['Накладну видалено', 'gone'],
        3   => ['Номер не знайдено', 'gone'],
        4   => ['У місті відправника', 'transit'],
        41  => ['У дорозі', 'transit'],
        5   => ['Прямує до міста отримувача', 'transit'],
        6   => ['У місті отримувача', 'transit'],
        7   => ['Прибула у відділення', 'arrived'],
        8   => ['У поштоматі', 'arrived'],
        9   => ['Отримано', 'done'],
        10  => ['Отримано, переказ у дорозі', 'done'],
        11  => ['Отримано, переказ видано', 'done'],
        12  => ['Комплектується', 'transit'],
        101 => ['На шляху до одержувача', 'transit'],
        102 => ['Відмова відправника', 'problem'],
        103 => ['Відмова одержувача', 'problem'],
        104 => ['Змінено адресу', 'transit'],
        105 => ['Припинено зберігання', 'problem'],
        106 => ['Отримано, оформлено повернення', 'problem'],
        111 => ['Невдала спроба доставки', 'problem'],
        112 => ['Дату доставки перенесено', 'arrived'],
    ];

    public static function key(): string
    {
        return trim((string)Settings::get('np_api_key', ''));
    }

    public static function enabled(): bool
    {
        return self::key() !== '';
    }

    /** Підпис статусу за кодом; невідомий код показуємо текстом самої НП */
    public static function statusLabel(?int $code, string $fallback = ''): string
    {
        $known = self::STATUSES[$code][0] ?? null;
        if ($known !== null) return $known;
        return trim($fallback) !== '' ? trim($fallback) : 'Статус уточнюється';
    }

    /** До чого зводиться статус: new|transit|arrived|done|problem|gone */
    public static function phase(?int $code): string
    {
        return self::STATUSES[$code][1] ?? 'transit';
    }

    // ─────────────────────────────────────────────────────────────── транспорт

    /**
     * Запит до API. Помилки НП повертає в полі errors при HTTP 200, тож
     * розрізняти «мережа впала» і «нам відмовили» доводиться самим.
     *
     * @return array{ok:bool,data:array,error:string}
     */
    public static function call(string $model, string $method, array $props = [], ?string $key = null): array
    {
        $key = $key !== null ? trim($key) : self::key();
        if ($key === '') return ['ok' => false, 'data' => [], 'error' => 'Немає API-ключа Нової Пошти'];

        $request = [
            'apiKey' => $key,
            'modelName' => $model,
            'calledMethod' => $method,
            'methodProperties' => $props,
        ];

        if (self::$transport) {
            $resp = (self::$transport)($request);
            return self::unwrap(is_array($resp) ? $resp : []);
        }

        $ch = curl_init(self::API);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($request, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            self::log("$model.$method: мережа — $err");
            return ['ok' => false, 'data' => [], 'error' => 'Нова Пошта не відповідає' . ($err !== '' ? ": $err" : '')];
        }
        $json = json_decode((string)$raw, true);
        if (!is_array($json)) {
            self::log("$model.$method: не JSON — " . mb_substr((string)$raw, 0, 300));
            return ['ok' => false, 'data' => [], 'error' => 'Нова Пошта відповіла незрозумілим'];
        }
        $out = self::unwrap($json);
        if (!$out['ok']) self::log("$model.$method: " . $out['error']);
        return $out;
    }

    /** Відповідь НП у нашу форму. warnings теж пояснюють відмову, тож беремо і їх. */
    private static function unwrap(array $json): array
    {
        $ok = !empty($json['success']);
        $problems = array_merge(
            array_values((array)($json['errors'] ?? [])),
            $ok ? [] : array_values((array)($json['warnings'] ?? []))
        );
        return [
            'ok' => $ok,
            'data' => (array)($json['data'] ?? []),
            'error' => $ok ? ''
                : (implode('; ', array_map('strval', $problems)) ?: 'Нова Пошта відмовила без пояснення'),
        ];
    }

    // ─────────────────────────────────────────────────────────────── довідники

    /** Пошук населеного пункту: [['ref','label','area']] */
    public static function settlements(string $q, int $limit = 10): array
    {
        $r = self::call('Address', 'searchSettlements', ['CityName' => $q, 'Limit' => (string)$limit]);
        $out = [];
        foreach (($r['data'][0]['Addresses'] ?? []) as $a) {
            // Ref населеного пункту, а не DeliveryCity: за DeliveryCity getWarehouses
            // для частини міст (напр. Кривого Рогу) віддає порожній список
            if (empty($a['Ref'])) continue;
            $out[] = [
                'ref' => (string)$a['Ref'],
                'label' => (string)($a['Present'] ?? ''),
                'area' => (string)($a['Area'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Відділення й поштомати міста: [['ref','label','number','postomat']].
     *
     * FindByString перекладає пошук на саму НП: у Києві 7700+ точок, і будь-який
     * локальний ліміт обрізав список на перших відділеннях.
     */
    public static function warehouses(string $cityRef, string $q = '', int $limit = 50): array
    {
        if ($cityRef === '') return [];
        $props = ['SettlementRef' => $cityRef, 'Limit' => (string)$limit, 'Page' => '1'];
        if ($q !== '') $props['FindByString'] = $q;
        $r = self::call('Address', 'getWarehouses', $props);
        $out = [];
        foreach (($r['data'] ?? []) as $w) {
            if (empty($w['Description'])) continue;
            $out[] = [
                'ref' => (string)($w['Ref'] ?? ''),
                'label' => (string)$w['Description'],
                'number' => (string)($w['Number'] ?? ''),
                'postomat' => str_contains(mb_strtolower((string)$w['Description']), 'поштомат'),
            ];
        }
        return $out;
    }

    /** Вулиці міста для курʼєрської доставки: [['ref','label']] */
    public static function streets(string $cityRef, string $q, int $limit = 30): array
    {
        if ($cityRef === '' || $q === '') return [];
        $r = self::call('Address', 'searchSettlementStreets',
            ['StreetName' => $q, 'SettlementRef' => $cityRef, 'Limit' => (string)$limit]);
        $out = [];
        foreach (($r['data'][0]['Addresses'] ?? []) as $s) {
            if (empty($s['SettlementStreetRef'])) continue;
            $out[] = [
                'ref' => (string)$s['SettlementStreetRef'],
                'label' => trim((string)($s['StreetsType'] ?? '') . ' '
                    . (string)($s['SettlementStreetDescription'] ?? '')),
            ];
        }
        return $out;
    }

    /** Контрагенти-відправники кабінету (їх зазвичай один-два) */
    public static function senders(): array
    {
        $r = self::call('Counterparty', 'getCounterparties',
            ['CounterpartyProperty' => 'Sender', 'Page' => '1']);
        $out = [];
        foreach (($r['data'] ?? []) as $c) {
            if (empty($c['Ref'])) continue;
            $out[] = ['ref' => (string)$c['Ref'], 'label' => (string)($c['Description'] ?? $c['Ref'])];
        }
        return $out;
    }

    /** Контактні особи відправника: саме вони підписують накладну */
    public static function senderContacts(string $counterpartyRef): array
    {
        if ($counterpartyRef === '') return [];
        $r = self::call('Counterparty', 'getCounterpartyContactPersons',
            ['Ref' => $counterpartyRef, 'Page' => '1']);
        $out = [];
        foreach (($r['data'] ?? []) as $c) {
            if (empty($c['Ref'])) continue;
            $out[] = [
                'ref' => (string)$c['Ref'],
                'label' => (string)($c['Description'] ?? ''),
                'phone' => (string)($c['Phones'] ?? ''),
            ];
        }
        return $out;
    }

    // ─────────────────────────────────────────────────────────────── отримувач

    /**
     * Приватний отримувач у довіднику НП. Створювати його доводиться до
     * накладної: у полі Recipient очікується Ref, а не імʼя. Повторний виклик із
     * тим самим телефоном НП зводить на наявний запис сама — дублікатів це не
     * плодить.
     *
     * @return array{ok:bool,ref:string,contact:string,error:string}
     */
    public static function recipient(string $name, string $phone): array
    {
        [$last, $first, $middle] = self::splitName($name);
        $r = self::call('Counterparty', 'save', [
            'FirstName' => $first,
            'MiddleName' => $middle,
            'LastName' => $last,
            'Phone' => self::phone($phone),
            'CounterpartyType' => 'PrivatePerson',
            'CounterpartyProperty' => 'Recipient',
        ]);
        if (!$r['ok']) return ['ok' => false, 'ref' => '', 'contact' => '', 'error' => $r['error']];
        $row = $r['data'][0] ?? [];
        return [
            'ok' => true,
            'ref' => (string)($row['Ref'] ?? ''),
            'contact' => (string)($row['ContactPerson']['data'][0]['Ref'] ?? ''),
            'error' => '',
        ];
    }

    /**
     * Адреса приватного отримувача для курʼєрської доставки. Відділенню такого
     * не треба — там адресою слугує Ref самого відділення.
     */
    public static function recipientAddress(string $counterpartyRef, string $streetRef, string $house, string $flat): array
    {
        $r = self::call('Address', 'save', [
            'CounterpartyRef' => $counterpartyRef,
            'StreetRef' => $streetRef,
            'BuildingNumber' => $house !== '' ? $house : '1',
            'Flat' => $flat,
        ]);
        if (!$r['ok']) return ['ok' => false, 'ref' => '', 'error' => $r['error']];
        return ['ok' => true, 'ref' => (string)($r['data'][0]['Ref'] ?? ''), 'error' => ''];
    }

    // ──────────────────────────────────────────────────────────────── накладна

    /**
     * Створити експрес-накладну. $p — уже підготовлені поля (див. Shipments::create):
     * тут ми лише перекладаємо їх мовою НП і не здогадуємось про значення.
     *
     * @return array{ok:bool,number:string,ref:string,cost:float,date:string,error:string}
     */
    public static function createDocument(array $p): array
    {
        $props = [
            'PayerType' => $p['payer'],                 // Sender|Recipient
            'PaymentMethod' => $p['payment'],           // Cash|NonCash
            'DateTime' => $p['date'],                   // d.m.Y — день відправлення
            'CargoType' => 'Parcel',
            'Weight' => (string)$p['weight'],
            'ServiceType' => $p['service'],             // WarehouseWarehouse|WarehouseDoors
            'SeatsAmount' => (string)$p['seats'],
            'Description' => $p['description'],
            'Cost' => (string)$p['cost'],
            'CitySender' => $p['sender_city'],
            'Sender' => $p['sender'],
            'SenderAddress' => $p['sender_address'],
            'ContactSender' => $p['sender_contact'],
            'SendersPhone' => self::phone((string)$p['sender_phone']),
            'CityRecipient' => $p['city'],
            'Recipient' => $p['recipient'],
            'RecipientAddress' => $p['recipient_address'],
            'ContactRecipient' => $p['recipient_contact'],
            'RecipientsPhone' => self::phone((string)$p['recipient_phone']),
        ];
        // Післяплата — це окрема послуга зворотної доставки грошей, а не спосіб
        // оплати доставки. Без BackwardDeliveryData гроші просто не поїдуть назад.
        if (!empty($p['cod']) && (float)$p['cod'] > 0) {
            $props['BackwardDeliveryData'] = [[
                'PayerType' => $p['cod_payer'] ?? 'Recipient',
                'CargoType' => 'Money',
                'RedeliveryString' => (string)$p['cod'],
            ]];
        }

        $r = self::call('InternetDocument', 'save', $props);
        if (!$r['ok']) {
            return ['ok' => false, 'number' => '', 'ref' => '', 'cost' => 0.0, 'date' => '', 'error' => $r['error']];
        }
        $d = $r['data'][0] ?? [];
        return [
            'ok' => true,
            'number' => (string)($d['IntDocNumber'] ?? ''),
            'ref' => (string)($d['Ref'] ?? ''),
            'cost' => (float)($d['CostOnSite'] ?? 0),
            'date' => (string)($d['EstimatedDeliveryDate'] ?? ''),
            'error' => '',
        ];
    }

    /** Видалити накладну — можливо, поки її не прийняли у відділенні */
    public static function deleteDocument(string $ref): array
    {
        if ($ref === '') return ['ok' => false, 'data' => [], 'error' => 'Немає посилання на накладну'];
        return self::call('InternetDocument', 'delete', ['DocumentRefs' => $ref]);
    }

    /**
     * Статуси пачкою. НП приймає до 100 накладних за запит — саме тому трекінг і
     * зроблений пачками: сто запитів на сотню посилок вичерпали б денний ліміт.
     *
     * @param array<int,array{number:string,phone:string}> $docs
     * @return array<string,array> рядок відповіді НП за номером накладної
     */
    public static function track(array $docs): array
    {
        if (!$docs) return [];
        $documents = [];
        foreach (array_slice($docs, 0, 100) as $d) {
            $number = trim((string)($d['number'] ?? ''));
            if ($number === '') continue;
            // Телефон не обовʼязковий, але з ним НП віддає повні дані —
            // зокрема суму післяплати й дату отримання
            $documents[] = ['DocumentNumber' => $number, 'Phone' => self::phone((string)($d['phone'] ?? ''))];
        }
        if (!$documents) return [];

        $r = self::call('TrackingDocument', 'getStatusByPhone', ['Documents' => $documents]);
        if (!$r['ok']) return [];
        $out = [];
        foreach ($r['data'] as $row) {
            $number = (string)($row['Number'] ?? '');
            if ($number === '') continue;
            $out[$number] = $row;
        }
        return $out;
    }

    // ─────────────────────────────────────────────────────────────── дрібниці

    /**
     * Телефон у форматі, який приймає НП: 10 цифр без плюса. Наш нормалізатор
     * тримає номери як +380XXXXXXXXX, тож відрізаємо код країни.
     */
    public static function phone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (str_starts_with($digits, '380')) $digits = substr($digits, 2);
        if (strlen($digits) === 9) $digits = '0' . $digits;
        return $digits;
    }

    /**
     * «Шевченко Тарас Григорович» → [прізвище, імʼя, по батькові].
     *
     * НП вимагає прізвище й імʼя окремими полями, а в замовленні лежить один
     * рядок, який людина написала як їй зручно. Тому: перше слово — прізвище
     * (так підписують посилки), друге — імʼя, третє — по батькові. Одне слово
     * дублюємо в обидва поля: порожнє імʼя накладну не пропустить, а вигадувати
     * людині імʼя ми не станемо.
     */
    public static function splitName(string $name): array
    {
        $parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $last = $parts[0] ?? 'Отримувач';
        $first = $parts[1] ?? $last;
        $middle = $parts[2] ?? '';
        return [$last, $first, $middle];
    }

    public static function log(string $msg): void
    {
        $dir = BOFU_ROOT . '/storage/logs';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        @file_put_contents($dir . '/novaposhta.log', now() . ' ' . $msg . "\n", FILE_APPEND);
    }
}
