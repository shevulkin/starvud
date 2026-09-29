<?php
/**
 * Накладні Нової Пошти. Запуск: php bin/cli.php test
 *
 * Головне, що доводимо: у Нову Пошту йде рівно те, що ми маємо на увазі, а те,
 * що вона відповіла, правильно доходить до замовлення й до покупця.
 *
 * Живого API тут немає й бути не може: кожна накладна — це справжня посилка й
 * справжні гроші. Тому транспорт підмінений (NovaPoshta::$transport), і саме
 * це дає перевірити найцінніше — вміст запиту. Помилка в полі «відділення
 * отримувача» не падає з винятком: вона просто відправляє посилку не туди, і
 * дізнаються про це від покупця.
 *
 * Тест створює власні замовлення й налаштування та прибирає їх за собою.
 */
declare(strict_types=1);

final class ShipmentTest
{
    private int $pass = 0;
    private int $fail = 0;
    private int $parent = 0;
    private int $child = 0;
    private int $store = 0;
    private array $savedSettings = [];
    /** @var array<int,array> запити, які пішли б у НП */
    private array $sent = [];

    private const CITY_REF = '00000000-0000-0000-0000-0000000000c1';
    private const OFFICE_REF = '00000000-0000-0000-0000-0000000000f1';
    private const SENDER_CITY = '00000000-0000-0000-0000-0000000000s1';
    private const SENDER_WH = '00000000-0000-0000-0000-0000000000s2';

    public function run(): int
    {
        $this->setUp();
        try {
            $this->testPhone();
            $this->testNames();
            $this->testStatusMap();
            $this->testMissing();
            $this->testCreate();
            $this->testCreateTwice();
            $this->testTracking();
            $this->testAttach();
            $this->testDue();
            $this->testLetters();
        } finally {
            $this->tearDown();
        }
        echo "\n" . ($this->fail === 0
            ? "УСЕ ДОБРЕ: {$this->pass} перевірок\n"
            : "ПРОВАЛЕНО: {$this->fail} з " . ($this->pass + $this->fail) . "\n");
        return $this->fail === 0 ? 0 : 1;
    }

    // ── обстановка ──────────────────────────────────────────────────────────

    private function setUp(): void
    {
        $this->cleanup();

        // Налаштування відправника підміняємо на час тесту й повертаємо назад:
        // база тут спільна з робочою, і залишити в ній свій «ключ» було б гірше
        // за будь-який провалений тест.
        foreach (['np_api_key' => 'test-key',
                  'np_sender_ref' => 'sender-ref', 'np_sender_contact_ref' => 'contact-ref',
                  'np_sender_phone' => '+380501112233',
                  'np_sender_city_ref' => self::SENDER_CITY, 'np_sender_warehouse_ref' => self::SENDER_WH,
                  'np_description' => 'Мед', 'np_weight_default' => '0.5', 'np_seats_default' => '1',
                  'np_payer' => 'Recipient', 'np_payment' => 'Cash'] as $key => $value) {
            $this->savedSettings[$key] = Settings::get($key);
            Settings::set($key, $value);
        }

        $this->store = (int)DB::insert('stores', [
            'name' => 'Тестова точка ТТН', 'slug' => 'ttn-test-' . random_int(1000, 9999),
            'city' => 'Київ', 'active' => 1, 'sort' => 999,
        ]);

        // Замовлення гостя (без акаунта й пошти): так сповіщення нікому не
        // йдуть, а вся інша логіка працює звичайним шляхом
        $head = [
            'number' => 'TTN-TEST-' . random_int(1000, 9999), 'token' => bin2hex(random_bytes(16)),
            'user_id' => null, 'name' => 'Шевченко Тарас Григорович', 'phone' => '+380671112233',
            'email' => null, 'delivery' => 'np', 'city' => 'м. Київ', 'city_ref' => self::CITY_REF,
            'np_office' => 'Відділення №5', 'np_office_ref' => self::OFFICE_REF, 'np_type' => 'warehouse',
            'status' => 'new', 'subtotal' => 500, 'discount' => 0, 'total' => 500, 'created_at' => now(),
        ];
        $this->parent = (int)DB::insert('orders', $head);
        $this->child = (int)DB::insert('orders', array_merge($head, [
            'number' => $head['number'] . '/1', 'token' => bin2hex(random_bytes(16)),
            'parent_id' => $this->parent, 'seq' => 1, 'store_id' => $this->store,
        ]));
        DB::insert('order_items', [
            'order_id' => $this->child, 'product_id' => null, 'variant_id' => null,
            'title' => 'Мед липовий', 'price' => 250, 'qty' => 2, 'sum' => 500, 'stock_taken' => 2,
        ]);
    }

    private function tearDown(): void
    {
        $this->cleanup();
        foreach ($this->savedSettings as $key => $value) Settings::set($key, (string)$value);
        NovaPoshta::$transport = null;
    }

    private function cleanup(): void
    {
        foreach (DB::all("SELECT id FROM orders WHERE number LIKE 'TTN-TEST-%'") as $o) {
            DB::delete('shipments', 'order_id = ? OR parent_id = ?', [(int)$o['id'], (int)$o['id']]);
            DB::delete('order_items', 'order_id = ?', [(int)$o['id']]);
            DB::delete('order_events', 'parent_id = ? OR order_id = ?', [(int)$o['id'], (int)$o['id']]);
            DB::delete('orders', 'id = ?', [(int)$o['id']]);
        }
        DB::delete('stores', "slug LIKE 'ttn-test-%'");
    }

    private function ok(string $what, bool $cond): void
    {
        if ($cond) { $this->pass++; echo "  ok   $what\n"; }
        else { $this->fail++; echo "  FAIL $what\n"; }
    }

    private function group(string $name): void { echo "\n== $name ==\n"; }

    /**
     * Підміна НП: запамʼятовує запит і віддає заготовлену відповідь.
     * $answers — [метод => відповідь]; невідомий метод означає, що ми пішли
     * туди, куди не збирались, і тест має це показати, а не мовчки пройти.
     */
    private function fakeNp(array $answers): void
    {
        $this->sent = [];
        NovaPoshta::$transport = function (array $req) use ($answers) {
            $this->sent[] = $req;
            $key = $req['modelName'] . '.' . $req['calledMethod'];
            return $answers[$key] ?? ['success' => false, 'errors' => ['несподіваний виклик ' . $key]];
        };
    }

    /** Останній запит указаного методу — саме його вміст ми й перевіряємо */
    private function lastSent(string $key): ?array
    {
        foreach (array_reverse($this->sent) as $req) {
            if ($req['modelName'] . '.' . $req['calledMethod'] === $key) return $req['methodProperties'];
        }
        return null;
    }

    private function child(): array { return OrderFlow::order($this->child) ?? []; }
    private function parent(): array { return OrderFlow::order($this->parent) ?? []; }

    // ── перевірки ───────────────────────────────────────────────────────────

    private function testPhone(): void
    {
        $this->group('телефон у форматі, який приймає НП');
        $this->ok('+380 відрізається до 10 цифр', NovaPoshta::phone('+380671112233') === '0671112233');
        $this->ok('пробіли й дужки не заважають', NovaPoshta::phone('+38 (067) 111-22-33') === '0671112233');
        $this->ok('локальний запис лишається як є', NovaPoshta::phone('0671112233') === '0671112233');
        $this->ok('девʼять цифр доповнюються нулем', NovaPoshta::phone('671112233') === '0671112233');
        $this->ok('порожній лишається порожнім', NovaPoshta::phone('') === '');
    }

    private function testNames(): void
    {
        $this->group('імʼя отримувача розкладається на поля НП');
        $this->ok('три слова — прізвище, імʼя, по батькові',
            NovaPoshta::splitName('Шевченко Тарас Григорович') === ['Шевченко', 'Тарас', 'Григорович']);
        $this->ok('два слова — прізвище та імʼя',
            NovaPoshta::splitName('Шевченко Тарас') === ['Шевченко', 'Тарас', '']);
        // Порожнє імʼя накладну не пропустить, а вигадувати людині імʼя не можна
        $this->ok('одне слово дублюється в обидва поля',
            NovaPoshta::splitName('Тарас') === ['Тарас', 'Тарас', '']);
        $this->ok('зайві пробіли не створюють порожніх полів',
            NovaPoshta::splitName('  Шевченко   Тарас  ') === ['Шевченко', 'Тарас', '']);
    }

    private function testStatusMap(): void
    {
        $this->group('статуси НП зводяться до наших станів');
        $this->ok('створено — ще не їде', NovaPoshta::phase(1) === 'new');
        $this->ok('у дорозі', NovaPoshta::phase(5) === 'transit');
        $this->ok('прибула у відділення', NovaPoshta::phase(7) === 'arrived');
        $this->ok('поштомат теж «прибула»', NovaPoshta::phase(8) === 'arrived');
        $this->ok('отримано', NovaPoshta::phase(9) === 'done');
        $this->ok('отримано з переказом теж отримано', NovaPoshta::phase(11) === 'done');
        $this->ok('відмова — проблема', NovaPoshta::phase(103) === 'problem');
        $this->ok('видалену накладну більше не питаємо', NovaPoshta::phase(2) === 'gone');
        // Невідомий код колись зʼявиться: НП додає статуси, і краще вважати
        // посилку такою, що їде, ніж мовчки закрити замовлення
        $this->ok('невідомий код вважаємо рухом', NovaPoshta::phase(9999) === 'transit');
        $this->ok('невідомому коду показуємо текст самої НП',
            NovaPoshta::statusLabel(9999, 'Щось нове') === 'Щось нове');
    }

    private function testMissing(): void
    {
        $this->group('чого бракує для накладної — видно до спроби');
        $this->ok('із заповненим усе гаразд', Shipments::missing($this->child(), $this->parent()) === []);

        // Найчастіший випадок: адреса є, а звʼязку з довідником немає —
        // старе замовлення або покупець вписав відділення руками
        $noRef = $this->parent();
        $noRef['np_office_ref'] = '';
        $gaps = Shipments::missing($this->child(), $noRef);
        $this->ok('відділення без ref спиняє створення', $gaps !== []);
        $this->ok('причина названа зрозуміло',
            str_contains(implode(' ', $gaps), 'відділення з довідника'));

        $noSender = $this->savedSettings;   // тимчасово прибираємо відправника
        Settings::set('np_sender_ref', '');
        $this->ok('без відправника накладну не створити',
            str_contains(implode(' ', Shipments::missing($this->child(), $this->parent())), 'відправника'));
        Settings::set('np_sender_ref', 'sender-ref');
        unset($noSender);

        $pickup = $this->parent();
        $pickup['delivery'] = 'pickup';
        $this->ok('самовивозу накладна ні до чого',
            str_contains(implode(' ', Shipments::missing($this->child(), $pickup)), 'не на Нову Пошту'));
    }

    private function testCreate(): void
    {
        $this->group('створення накладної');
        $this->fakeNp([
            'Counterparty.save' => ['success' => true, 'data' => [[
                'Ref' => 'rcp-ref',
                'ContactPerson' => ['data' => [['Ref' => 'rcp-contact']]],
            ]]],
            'InternetDocument.save' => ['success' => true, 'data' => [[
                'IntDocNumber' => '20450000000001', 'Ref' => 'doc-ref',
                'CostOnSite' => 75, 'EstimatedDeliveryDate' => '18.08.2026',
            ]]],
        ]);

        $r = Shipments::create($this->child(), $this->parent(), [
            'weight' => '1,2', 'seats' => '2', 'cost' => '500', 'cod' => '500',
            'description' => 'Мед липовий', 'payer' => 'Recipient', 'payment' => 'Cash',
        ]);
        $this->ok('накладну створено', $r['ok'] === true);
        $this->ok('номер збережено', ($r['shipment']['number'] ?? '') === '20450000000001');

        $doc = $this->lastSent('InternetDocument.save') ?? [];
        $this->ok('відправник — з налаштувань', ($doc['Sender'] ?? '') === 'sender-ref');
        $this->ok('відділення відправлення — з налаштувань', ($doc['SenderAddress'] ?? '') === self::SENDER_WH);
        $this->ok('місто отримувача — ref із замовлення', ($doc['CityRecipient'] ?? '') === self::CITY_REF);
        // Найдорожча помилка: посилка їде не в те відділення. Перевіряємо явно.
        $this->ok('адреса отримувача — ref відділення', ($doc['RecipientAddress'] ?? '') === self::OFFICE_REF);
        $this->ok('відділення-відділення, а не курʼєр', ($doc['ServiceType'] ?? '') === 'WarehouseWarehouse');
        $this->ok('телефон отримувача у форматі НП', ($doc['RecipientsPhone'] ?? '') === '0671112233');
        // Кома як десятковий знак — саме її дає українська розкладка
        $this->ok('вага з комою прочитана як 1.2', (float)($doc['Weight'] ?? 0) === 1.2);
        $this->ok('місць передано 2', (string)($doc['SeatsAmount'] ?? '') === '2');
        $this->ok('післяплата поїхала окремою послугою',
            ((float)($doc['BackwardDeliveryData'][0]['RedeliveryString'] ?? 0)) === 500.0);
        $this->ok('післяплата — грошима', ($doc['BackwardDeliveryData'][0]['CargoType'] ?? '') === 'Money');

        $sh = Shipments::forOrder($this->child);
        $this->ok('накладна прикріплена до частини, а не до замовлення', $sh !== null);
        $this->ok('вартість доставки збережено', (float)$sh['delivery_cost'] === 75.0);
        $this->ok('орієнтовна дата розібрана', str_starts_with((string)$sh['estimated_at'], '2026-08-18'));
        $this->ok('покупцю вже сказано про номер', (string)$sh['notified_phase'] === 'new');

        // Накладна означає, що посилку зібрано й передають перевізнику —
        // змушувати продавця робити ту саму дію двічі не можна
        $this->ok('частина перейшла в «В дорозі»', $this->child()['status'] === 'shipped');
        $this->ok('замовлення теж рушило', $this->parent()['status'] === 'shipped');

        $events = DB::all("SELECT * FROM order_events WHERE parent_id = ? AND type = 'shipment'", [$this->parent]);
        $this->ok('у історії лишився слід', $events !== []);
        $this->ok('у сліді видно номер', str_contains((string)($events[0]['message'] ?? ''), '20450000000001'));
    }

    private function testCreateTwice(): void
    {
        $this->group('двох накладних на одну частину не буває');
        $r = Shipments::create($this->child(), $this->parent(), []);
        $this->ok('повторне створення відхилено', $r['ok'] === false);
        $this->ok('причина названа', str_contains($r['error'], 'вже є'));
        $this->ok('другого рядка не зʼявилось',
            (int)DB::val('SELECT COUNT(*) FROM shipments WHERE order_id = ?', [$this->child]) === 1);
    }

    private function testTracking(): void
    {
        $this->group('трекінг рухає замовлення й пише покупцю');
        $sh = Shipments::forOrder($this->child);

        // 7 — прибула у відділення: покупцю це знати треба
        $this->fakeNp(['TrackingDocument.getStatusByPhone' => ['success' => true, 'data' => [[
            'Number' => $sh['number'], 'StatusCode' => '7', 'Status' => 'Прибув у відділення',
        ]]]]);
        $changed = Shipments::refresh([$sh]);
        $sh = Shipments::forOrder($this->child);
        $this->ok('стан змінився', $changed === 1);
        $this->ok('фаза — «чекає у відділенні»', (string)$sh['phase'] === 'arrived');
        $this->ok('покупцю сказали саме про це', (string)$sh['notified_phase'] === 'arrived');
        $this->ok('замовлення лишилось «В дорозі»', $this->child()['status'] === 'shipped');
        $this->ok('час перевірки записано', !empty($sh['tracked_at']));

        // Той самий статус удруге — тиша: інакше кожна година cron слала б
        // «посилка у відділенні», поки її не заберуть
        $before = count(DB::all("SELECT id FROM order_events WHERE parent_id = ? AND type = 'shipment'", [$this->parent]));
        $this->ok('повторна відповідь нічого не змінює', Shipments::refresh([$sh]) === 0);
        $this->ok('другого запису в історії немає',
            count(DB::all("SELECT id FROM order_events WHERE parent_id = ? AND type = 'shipment'", [$this->parent])) === $before);

        // 9 — отримано: замовлення закривається саме
        $this->fakeNp(['TrackingDocument.getStatusByPhone' => ['success' => true, 'data' => [[
            'Number' => $sh['number'], 'StatusCode' => '9', 'Status' => 'Відправлення отримано',
            'RecipientDateTime' => '20.08.2026 14:30:00',
        ]]]]);
        Shipments::refresh([$sh]);
        $sh = Shipments::forOrder($this->child);
        $this->ok('фаза — отримано', (string)$sh['phase'] === 'done');
        $this->ok('дату отримання записано', str_starts_with((string)$sh['delivered_at'], '2026-08-20'));
        $this->ok('частина закрилась сама', $this->child()['status'] === 'done');
        $this->ok('замовлення теж закрилось', $this->parent()['status'] === 'done');

        // Телефон отримувача йде в запит: із ним НП віддає повні дані
        $props = $this->lastSent('TrackingDocument.getStatusByPhone') ?? [];
        $this->ok('у трекінг пішов телефон замовлення',
            ($props['Documents'][0]['Phone'] ?? '') === '0671112233');
    }

    private function testAttach(): void
    {
        $this->group('номер, вписаний руками');
        // Друга частина того самого замовлення — накладну оформили на відділенні
        $second = (int)DB::insert('orders', array_merge($this->parent(), [
            'id' => null, 'number' => $this->parent()['number'] . '/2', 'token' => bin2hex(random_bytes(16)),
            'parent_id' => $this->parent, 'seq' => 2, 'store_id' => $this->store, 'status' => 'new',
        ]));
        $child = OrderFlow::order($second);

        $this->fakeNp(['TrackingDocument.getStatusByPhone' => ['success' => true, 'data' => [[
            'Number' => '20450000000002', 'StatusCode' => '4', 'Status' => 'Відправлення у місті',
        ]]]]);

        $bad = Shipments::attach($child, $this->parent(), '12345');
        $this->ok('короткий номер відхилено', $bad['ok'] === false);
        $this->ok('сказано, скільки має бути цифр', str_contains($bad['error'], '14'));

        $r = Shipments::attach($child, $this->parent(), '2045 0000 000002');
        $this->ok('номер із пробілами прийнято', $r['ok'] === true);
        $sh = Shipments::forOrder($second);
        $this->ok('джерело позначено як ручне', (string)$sh['source'] === 'manual');
        // Вписаний номер одразу питаємо в НП: продавець має побачити, що він живий
        $this->ok('статус підтягнувся одразу', (string)$sh['phase'] === 'transit');
        $this->ok('частина рушила', OrderFlow::order($second)['status'] === 'shipped');

        $dup = Shipments::attach($child, $this->parent(), '20450000000002');
        $this->ok('той самий номер удруге не прикріплюється', $dup['ok'] === false);
    }

    /**
     * Те, що читає покупець. Перевіряємо не «красиво чи ні» — це не автоматизується,
     * — а речі, які ламаються тихо: тема листа, зайві рядки після отримання й те,
     * що правка адміна не затирається новим типовим текстом.
     */
    private function testLetters(): void
    {
        $this->group('лист покупцю');

        $parent = $this->parent();
        $sh = Shipments::forOrder($this->child);          // ця вже «отримана»
        $vars = Shipments::vars($sh, $this->child(), $parent);

        $this->ok('тема називає подію, а не «сповіщення»',
            str_contains(Notify::subject('order_shipment', $vars), $parent['number']));
        $this->ok('тема коротка — без повного речення',
            !str_contains(Notify::subject('order_shipment', $vars), '.'));

        // Після отримання ці рядки втрачають сенс і мають зникнути цілком
        $this->ok('отриманій посилці не обіцяють дату доставки', $vars['estimated'] === '');
        $this->ok('в отриманої не просять грошей', $vars['cod'] === '');
        $this->ok('отриману не пропонують відстежити', $vars['url'] === '');
        $this->ok('лист підписаний магазином', $vars['shop'] === (string)cfg('app_name'));

        $text = Notify::interpolate(Notify::template('order_shipment', null), $vars);
        $this->ok('номер накладної в тексті є', str_contains($text, (string)$sh['number']));
        $this->ok('дір із порожніх рядків немає', !str_contains($text, "\n\n\n"));
        $this->ok('текст не обривається порожнім рядком', !str_ends_with($text, "\n"));

        // Поки в базі лежить дослівно старий типовий текст — показуємо новий;
        // щойно адмін щось змінив — його варіант недоторканний
        $legacy = "🚚 Замовлення {number}\n{part}\nНакладна: {ttn}\n{status}\n{estimated}\n{cod}\n{url}";
        $this->ok('старий типовий текст поступається новому',
            Notify::template('order_shipment', $legacy) === Notify::DEFAULT_TEMPLATES['order_shipment']);
        $this->ok('порожній шаблон бере типовий',
            Notify::template('order_shipment', '') === Notify::DEFAULT_TEMPLATES['order_shipment']);
        $this->ok('правку адміна не чіпаємо',
            Notify::template('order_shipment', 'Мій текст {ttn}') === 'Мій текст {ttn}');
    }

    private function testDue(): void
    {
        $this->group('трекати варто не всі накладні');
        $numbers = array_column(Shipments::due(100), 'number');
        $this->ok('отриману більше не питаємо', !in_array('20450000000001', $numbers, true));
        $this->ok('ту, що в дорозі, — питаємо', in_array('20450000000002', $numbers, true));
    }
}

return (new ShipmentTest())->run();
