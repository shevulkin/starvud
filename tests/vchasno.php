<?php
/**
 * Фіскальні чеки «Вчасно.Каса».  Запуск: php bin/cli.php test
 *
 * Живої каси тут немає й бути не може: кожен чек справжній, іде в ДПС і
 * скасовується лише чеком повернення. Тому транспорт підмінено — і саме це
 * робить набір змістовним: перевіряємо не «чи відповів сервер», а ЩО САМЕ ми
 * йому надсилаємо. Помилка в тілі запиту дорога в обидва боки: недобрана
 * копійка валить увесь чек посеред черги, а зайвий чек після обриву звʼязку
 * доводиться повертати руками в кабінеті.
 *
 * Найважливіше тут — три речі:
 *   1) сума чека сходиться з рядками до копійки, а знижка розкладається так,
 *      що залишок не губиться;
 *   2) обрив звʼязку лишає чек «непевним», а повтор іде з ТІЄЮ САМОЮ міткою —
 *      інакше один продаж перетворюється на два чеки;
 *   3) податкова група береться в правильному порядку: товар → магазин →
 *      загальна.
 *
 * Тест створює власні товари, магазин і замовлення та прибирає їх за собою.
 */
declare(strict_types=1);

final class VchasnoTest
{
    private int $pass = 0;
    private int $fail = 0;
    private int $store = 0;
    private int $product = 0;
    private int $product2 = 0;
    private array $parentIds = [];
    private array $settingsWas = [];
    private string $notifyWas = '1';

    /** Що пішло в касу останнім разом — на цьому й тримається весь набір */
    private array $sent = [];

    public function run(): int
    {
        $stores = Catalog::stores();
        if (!$stores) { echo "  — немає активних магазинів, пропускаємо\n"; return 0; }
        $this->store = (int)$stores[0]['id'];

        $this->setUp();
        try {
            $this->testClean();
            $this->testTaxGroup();
            $this->testRowsSum();
            $this->testDiscountSplit();
            $this->testCashRounding();
            $this->testSaleBody();
            $this->testUnclearKeepsTag();
            $this->testErrorIsFinal();
            $this->testRefund();
            $this->testGoodsCompare();
            $this->testSheetRoundTrip();
        } finally {
            $this->tearDown();
        }
        echo "\n" . ($this->fail === 0
            ? "УСЕ ДОБРЕ: {$this->pass} перевірок\n"
            : "ПРОВАЛЕНО: {$this->fail} з " . ($this->pass + $this->fail) . "\n");
        return $this->fail === 0 ? 0 : 1;
    }

    // ─────────────────────────────────────────────────────────────── оснастка

    private function setUp(): void
    {
        $this->notifyWas = (string)Settings::get('notify_all_enabled', '1');
        Settings::set('notify_all_enabled', '0');
        foreach (['vchasno_token', 'vchasno_taxgrp', 'vchasno_cash_round', 'vchasno_send_link',
                  'vchasno_auto_pos', 'vchasno_comment_down', 'fiscal_provider', 'fiscal_route'] as $k) {
            $this->settingsWas[$k] = Settings::get($k, null);
        }
        // Набір перевіряє саме «Вчасно» — магазин може працювати з іншим ПРРО
        Settings::set('fiscal_provider', 'vchasno');
        Settings::set('fiscal_route', 'cloud');
        Settings::set('vchasno_token', 'TEST-TOKEN');
        Settings::set('vchasno_taxgrp', '2');
        Settings::set('vchasno_cash_round', '1');
        Settings::set('vchasno_send_link', '0');   // userinfo перевіряємо окремо, а не в кожному чеку
        Settings::set('vchasno_comment_down', '');

        $cat = (int)(DB::val('SELECT id FROM categories ORDER BY id LIMIT 1') ?? 0);
        $this->product = DB::insert('products', [
            'category_id' => $cat, 'name' => 'Тест: мед для чека',
            'slug' => 'test-fiscal-' . bin2hex(random_bytes(3)),
            'sku' => 'TEST-FISC-1', 'barcode' => '4820000009991',
            'base_price' => 100, 'active' => 1, 'made_to_order' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->product2 = DB::insert('products', [
            'category_id' => $cat, 'name' => 'Тест: пилок для чека',
            'slug' => 'test-fiscal2-' . bin2hex(random_bytes(3)),
            'sku' => 'TEST-FISC-2', 'barcode' => '4820000009992',
            'base_price' => 33.33, 'active' => 1, 'made_to_order' => 0,
            'taxgrp' => 1,          // власна група товару — старша за магазинну
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([$this->product, $this->product2] as $pid) {
            DB::insert('store_stock', ['product_id' => $pid, 'variant_id' => null,
                                       'store_id' => $this->store, 'qty' => 50]);
        }
    }

    private function tearDown(): void
    {
        Vchasno::$transport = null;
        foreach ($this->parentIds as $pid) {
            foreach (DB::all('SELECT id FROM orders WHERE parent_id = ? OR id = ?', [$pid, $pid]) as $o) {
                DB::delete('order_items', 'order_id = ?', [(int)$o['id']]);
                DB::delete('fiscal_receipts', 'order_id = ?', [(int)$o['id']]);
            }
            DB::delete('fiscal_receipts', 'parent_id = ?', [$pid]);
            DB::delete('order_events', 'parent_id = ?', [$pid]);
            DB::delete('orders', 'parent_id = ?', [$pid]);
            DB::delete('orders', 'id = ?', [$pid]);
        }
        foreach ([$this->product, $this->product2] as $pid) {
            if (!$pid) continue;
            DB::delete('store_stock', 'product_id = ?', [$pid]);
            DB::delete('products', 'id = ?', [$pid]);
        }
        DB::update('stores', ['vchasno_taxgrp' => null, 'vchasno_token' => null], 'id = ?', [$this->store]);
        foreach ($this->settingsWas as $k => $v) Settings::set($k, $v ?? '');
        Settings::set('notify_all_enabled', $this->notifyWas);
    }

    private function ok(string $what, bool $cond): void
    {
        if ($cond) { $this->pass++; echo "  ok   $what\n"; }
        else { $this->fail++; echo "  FAIL $what\n"; }
    }

    private function group(string $name): void { echo "\n== $name ==\n"; }

    /**
     * Каса, яка все приймає. Запамʼятовує запит — заради нього все й затіяно.
     */
    private function answering(string $doccode = 'TEST_OK_1'): void
    {
        $this->sent = [];
        Vchasno::$transport = function (array $req) use ($doccode) {
            $this->sent = $req;
            $task = (int)($req['body']['fiscal']['task'] ?? 0);
            return [
                'task' => $task, 'res' => 0, 'res_action' => 0, 'errortxt' => '',
                'info' => [
                    'task' => $task, 'fisid' => '99997955555555', 'doccode' => $doccode,
                    'dt' => date('YmdHis'), 'dtype' => 1, 'isoffline' => false,
                    'shift_link' => 7, 'docno' => 1, 'safe' => 0,
                    'qr' => 'https://kasa.vchasno.ua/c/' . $doccode,
                ],
            ];
        };
    }

    /** Каса, якої немає в мережі: відповіді не буде взагалі */
    private function silent(): void
    {
        $this->sent = [];
        Vchasno::$transport = function (array $req) {
            $this->sent = $req;
            return [];   // порожня відповідь = ні res, ні info
        };
    }

    /** Каса, яка відмовляє по суті */
    private function refusing(string $why = 'Сума всіх позицій товару відрізняється від загальної суми чеку'): void
    {
        Vchasno::$transport = fn(array $req) => [
            'res' => 1001, 'res_action' => 3, 'errortxt' => $why,
            'error_extra' => ['sum' => 10050, 'rows_sum' => 10000],
        ];
    }

    /**
     * Замовлення з каси: головне + частина магазину, як його робить продаж.
     *
     * @param array<int, array{0:int,1:int,2:float}> $lines [id товару, кількість, ціна]
     * @return array{parent:array, child:array}
     */
    private function order(array $lines, float $discount = 0.0): array
    {
        $rows = [];
        $subtotal = 0.0;
        foreach ($lines as [$pid, $qty, $price]) {
            $p = DB::row('SELECT * FROM products WHERE id = ?', [$pid]);
            $sum = round($price * $qty, 2);
            $subtotal += $sum;
            $rows[] = ['product' => $p, 'variant' => null, 'qty' => $qty,
                       'price' => $price, 'old' => null, 'sum' => $sum];
        }
        $placed = OrderFlow::place([
            'number' => 'FISC-' . bin2hex(random_bytes(3)), 'token' => bin2hex(random_bytes(8)),
            'user_id' => null, 'name' => 'Покупець', 'phone' => '', 'email' => null,
            'delivery' => 'pickup', 'city' => null, 'np_office' => null, 'address' => null,
            'comment' => null, 'store_id' => $this->store,
            'source' => 'offline', 'created_by_user_id' => null,
            'status' => 'new', 'promo_code' => null,
            'subtotal' => $subtotal, 'discount' => $discount,
            'total' => max(0, round($subtotal - $discount, 2)), 'created_at' => now(),
        ], $rows, $this->store);
        $this->parentIds[] = (int)$placed['id'];

        return [
            'parent' => OrderFlow::order((int)$placed['id']),
            'child' => OrderFlow::order((int)$placed['children'][0]['id']),
        ];
    }

    /** Тіло чека з останнього запиту */
    private function receipt(): array
    {
        return (array)($this->sent['body']['fiscal']['receipt'] ?? []);
    }

    // ──────────────────────────────────────────────────────────────── перевірки

    private function testClean(): void
    {
        $this->group('назви чистяться під абетку ПРРО');
        $this->ok('емодзі зникає, слова лишаються',
            Vchasno::clean('Мед 🍯 липовий') === 'Мед липовий');
        $this->ok('нерозривний пробіл із копіпасти стає звичайним',
            Vchasno::clean("Мед\u{00A0}липовий") === 'Мед липовий');
        $this->ok('українські літери не чіпаємо',
            Vchasno::clean('Їжачок ґудзик Єва №1') === 'Їжачок ґудзик Єва №1');
        $this->ok('довжина обрізається', mb_strlen(Vchasno::clean(str_repeat('а', 300), 128)) === 128);
    }

    private function testTaxGroup(): void
    {
        $this->group('податкова група: товар → магазин → загальна');
        DB::update('stores', ['vchasno_taxgrp' => 4], 'id = ?', [$this->store]);
        $own = DB::row('SELECT * FROM products WHERE id = ?', [$this->product2]);   // taxgrp = 1
        $plain = DB::row('SELECT * FROM products WHERE id = ?', [$this->product]);  // без своєї

        $this->ok('своя група товару найстарша', Fiscal::taxGroup($own, $this->store) === 1);
        $this->ok('без своєї — береться магазинна', Fiscal::taxGroup($plain, $this->store) === 4);
        DB::update('stores', ['vchasno_taxgrp' => null], 'id = ?', [$this->store]);
        $this->ok('без магазинної — загальна з налаштувань', Fiscal::taxGroup($plain, $this->store) === 2);
        $this->ok('чуже число не приймається як група',
            Fiscal::taxGroup(['taxgrp' => 42], $this->store) === 2);
    }

    private function testRowsSum(): void
    {
        $this->group('сума чека сходиться з рядками');
        $o = $this->order([[$this->product, 3, 100.0], [$this->product2, 1, 33.33]]);
        $built = Fiscal::rows((int)$o['child']['id'], $this->store, 0.0);

        $sum = 0.0;
        foreach ($built['rows'] as $r) $sum = round($sum + $r['cnt'] * $r['price'] - $r['disc'], 2);
        $this->ok('рядків стільки ж, скільки позицій', count($built['rows']) === 2);
        $this->ok('порахована сума збігається з підсумком', abs($built['sum'] - 333.33) < 0.005);
        $this->ok('сума рядків = сума чека', abs($sum - $built['sum']) < 0.005);
        $this->ok('коди товару йдуть у чек',
            ($built['rows'][0]['code'] ?? '') === 'TEST-FISC-1'
            && ($built['rows'][0]['code1'] ?? '') === '4820000009991');
    }

    private function testDiscountSplit(): void
    {
        $this->group('знижка розкладається по рядках без втрати копійки');
        // 10 грн знижки на 100 + 33.33: пропорційний розподіл дає нецілі копійки,
        // і саме тут найлегше загубити або вигадати копійку
        $o = $this->order([[$this->product, 1, 100.0], [$this->product2, 1, 33.33]], 10.0);
        $child = OrderFlow::order((int)$o['child']['id']);
        $built = Fiscal::rows((int)$child['id'], $this->store, (float)$child['discount']);

        $cut = 0.0;
        foreach ($built['rows'] as $r) $cut = round($cut + $r['disc'], 2);
        $this->ok('роздано рівно стільки знижки, скільки в замовленні',
            abs($cut - (float)$child['discount']) < 0.005);
        $this->ok('сума чека = сума частини замовлення',
            abs($built['sum'] - (float)$child['total']) < 0.005);
        $this->ok('знижка не робить рядок відʼємним',
            array_reduce($built['rows'], fn($c, $r) => $c && $r['disc'] <= $r['cnt'] * $r['price'], true));
    }

    private function testCashRounding(): void
    {
        $this->group('готівка округлюється до 10 копійок окремим рядком');
        $o = $this->order([[$this->product2, 3, 33.33]]);   // 99.99
        $this->answering('TEST_ROUND');
        $r = Fiscal::sell($o['child'], $o['parent'], ['pay_type' => 0, 'got' => 100.0]);
        $rc = $this->receipt();

        $this->ok('чек пройшов', $r['ok']);
        $this->ok('сума чека — справжня сума товарів', abs((float)$rc['sum'] - 99.99) < 0.005);
        $this->ok('округлення поїхало окремим полем', abs((float)($rc['round'] ?? 0) - 0.01) < 0.005);
        $this->ok('оплата — округлена сума', abs((float)$rc['pays'][0]['sum'] - 100.0) < 0.005);
        $this->ok('решти немає, бо дали рівно', !isset($rc['pays'][0]['change']));

        // А тепер із решти
        $o2 = $this->order([[$this->product, 1, 100.0]]);
        $this->answering('TEST_CHANGE');
        Fiscal::sell($o2['child'], $o2['parent'], ['pay_type' => 0, 'got' => 500.0]);
        $rc2 = $this->receipt();
        $this->ok('решта рахується від округленої суми',
            abs((float)($rc2['pays'][0]['change'] ?? 0) - 400.0) < 0.005);

        // Картка копійок не боїться — округлювати нічого
        $o3 = $this->order([[$this->product2, 3, 33.33]]);
        $this->answering('TEST_CARD');
        Fiscal::sell($o3['child'], $o3['parent'], ['pay_type' => 2]);
        $rc3 = $this->receipt();
        $this->ok('картку не округлюємо', !isset($rc3['round'])
            && abs((float)$rc3['pays'][0]['sum'] - 99.99) < 0.005);
        $this->ok('вид оплати їде в чек', (int)$rc3['pays'][0]['type'] === 2);
    }

    private function testSaleBody(): void
    {
        $this->group('що саме йде в касу на продаж');
        $o = $this->order([[$this->product, 2, 100.0]]);
        $this->answering('TEST_BODY');
        $r = Fiscal::sell($o['child'], $o['parent'], ['pay_type' => 0, 'cashier' => 'Оксана']);

        $body = (array)($this->sent['body'] ?? []);
        $this->ok('завдання — чек продажу', (int)$body['fiscal']['task'] === Vchasno::TASK_SELL);
        $this->ok('касир — той, хто продав', ($body['fiscal']['cashier'] ?? '') === 'Оксана');
        $this->ok('мітка запиту не порожня', ($body['tag'] ?? '') !== '');
        $this->ok('токен узято з налаштувань', ($this->sent['token'] ?? '') === 'TEST-TOKEN');
        $this->ok('запит іде на фіскалізацію', ($this->sent['path'] ?? '') === '/api/v3/fiscal/execute');

        $saved = Fiscal::forOrder((int)$o['child']['id']);
        $this->ok('чек записано як проведений', $saved && $saved['status'] === 'done');
        $this->ok('фіскальний номер збережено', ($saved['fiscal_number'] ?? '') === 'TEST_BODY');
        $this->ok('мітка в базі та в запиті — та сама', ($saved['tag'] ?? '') === ($body['tag'] ?? 'x'));
        $this->ok('подія потрапила в історію замовлення',
            (bool)DB::val("SELECT 1 FROM order_events WHERE parent_id = ? AND type = 'fiscal'",
                          [(int)$o['parent']['id']]));
        $this->ok('другий чек на ту саму частину не пробʼється',
            !Fiscal::sell($o['child'], $o['parent'], ['pay_type' => 0])['ok']);
        $this->ok('sell повертає чек', ($r['receipt']['fiscal_number'] ?? '') === 'TEST_BODY');
    }

    private function testUnclearKeepsTag(): void
    {
        $this->group('каса змовчала: чек непевний, повтор — з тією самою міткою');
        $o = $this->order([[$this->product, 1, 100.0]]);
        $this->silent();
        $r = Fiscal::sell($o['child'], $o['parent'], ['pay_type' => 0]);
        $first = (array)($this->sent['body'] ?? []);

        $this->ok('невдача, але не помилка', !$r['ok']);
        $saved = Fiscal::forOrder((int)$o['child']['id']);
        $this->ok('чек лишився непевним', $saved && $saved['status'] === 'pending');
        $this->ok('тіло запиту збережено для повтору', ($saved['payload'] ?? '') !== '');
        $this->ok('такий чек потрапляє в чергу на перепит',
            in_array((int)$saved['id'], array_map(fn($x) => (int)$x['id'], Fiscal::due(50)), true));
        $this->ok('нового чека пробити не дасть — цей іще живий',
            !Fiscal::sell($o['child'], $o['parent'], ['pay_type' => 0])['ok']);

        // Каса ожила — повторюємо
        $this->answering('TEST_RETRY');
        $again = Fiscal::retry($saved);
        $second = (array)($this->sent['body'] ?? []);
        $this->ok('повтор пройшов', $again['ok']);
        $this->ok('мітка та сама — другого чека з цього не вийде',
            ($first['tag'] ?? 'a') === ($second['tag'] ?? 'b'));
        $this->ok('тіло повтору те саме',
            json_encode($first['fiscal'] ?? []) === json_encode($second['fiscal'] ?? []));
        $this->ok('чек став проведеним',
            (Fiscal::forOrder((int)$o['child']['id'])['status'] ?? '') === 'done');
    }

    private function testErrorIsFinal(): void
    {
        $this->group('відмова по суті: чека немає, причина видна');
        $o = $this->order([[$this->product, 1, 100.0]]);
        $this->refusing();
        $r = Fiscal::sell($o['child'], $o['parent'], ['pay_type' => 0]);

        $this->ok('чек не пройшов', !$r['ok']);
        $saved = DB::row('SELECT * FROM fiscal_receipts WHERE order_id = ? ORDER BY id DESC',
                         [(int)$o['child']['id']]);
        $this->ok('стан — помилка, а не «невідомо»', ($saved['status'] ?? '') === 'error');
        $this->ok('причину збережено', str_contains((string)$saved['error'], 'відрізняється'));
        $this->ok('деталі валідації теж збережено', str_contains((string)$saved['error'], 'rows_sum'));
        $this->ok('після відмови можна пробувати знову',
            !Fiscal::hasSale((int)$o['child']['id']));
    }

    private function testRefund(): void
    {
        $this->group('повернення дзеркалить чек продажу');
        $o = $this->order([[$this->product, 2, 100.0]]);
        $this->answering('TEST_SALE_R');
        Fiscal::sell($o['child'], $o['parent'], ['pay_type' => 2]);
        $sale = Fiscal::forOrder((int)$o['child']['id']);
        $soldRows = $this->receipt()['rows'];

        $this->answering('TEST_BACK_R');
        $back = Fiscal::refund($sale);
        $rc = $this->receipt();

        $this->ok('повернення пройшло', $back['ok']);
        $this->ok('завдання — чек повернення',
            (int)($this->sent['body']['fiscal']['task'] ?? 0) === Vchasno::TASK_RETURN);
        $this->ok('рядки ті самі, що продавали', json_encode($rc['rows']) === json_encode($soldRows));
        $this->ok('сума та сама', abs((float)$rc['sum'] - (float)$sale['sum']) < 0.005);
        $this->ok('вид оплати той самий', (int)$rc['pays'][0]['type'] === 2);
        $this->ok('повернення привʼязане до чека продажу',
            (int)($back['receipt']['of_receipt_id'] ?? 0) === (int)$sale['id']);
        $this->ok('двічі те саме не повернемо', !Fiscal::refund($sale)['ok']);
    }

    private function testGoodsCompare(): void
    {
        $this->group('звірка товарів із вивантаженням кабінету');
        // Їхній файл: у першого збіг за штрихкодом і інша ціна, другого в нас
        // немає взагалі, а «наш» пилок вони звуть інакше
        $theirs = [
            ['name' => 'Мед лип.', 'price' => 120.0, 'taxgrp' => 2,
             'sku' => 'IX-1', 'barcode' => '4820000009991', 'uktzed' => '', 'unit' => 'шт'],
            ['name' => 'Свічка воскова', 'price' => 40.0, 'taxgrp' => 2,
             'sku' => 'IX-9', 'barcode' => '4820000009999', 'uktzed' => '', 'unit' => 'шт'],
        ];
        $rep = VchasnoGoods::compare($theirs, $this->store);
        $mine = null; $orphan = null;
        foreach ($rep['rows'] as $r) {
            if (($r['ours']['product_id'] ?? 0) === $this->product) $mine = $r;
            if ($r['state'] === 'only_theirs' && ($r['theirs']['sku'] ?? '') === 'IX-9') $orphan = $r;
        }
        $this->ok('наш товар знайшовся за штрихкодом', $mine && $mine['match'] === 'штрихкод');
        $this->ok('різниця в ціні помічена', $mine && isset($mine['diff']['price']));
        $this->ok('їхній зайвий товар видно окремо', $orphan !== null);
        $this->ok('чужа назва не завадила збігу за кодом', $mine && $mine['state'] === 'differs');

        // Перенесення заповнює лише порожнє
        DB::update('products', ['taxgrp' => null], 'id = ?', [$this->product]);
        VchasnoGoods::apply($rep['rows']);
        $after = DB::row('SELECT * FROM products WHERE id = ?', [$this->product]);
        $this->ok('порожня податкова група заповнилась із файлу', (int)$after['taxgrp'] === 2);
        $this->ok('наш артикул не перезаписали їхнім', (string)$after['sku'] === 'TEST-FISC-1');
        $this->ok('ціну не чіпали — це рішення магазину', abs((float)$after['base_price'] - 100.0) < 0.005);
    }

    private function testSheetRoundTrip(): void
    {
        $this->group('файл туди й назад');
        $rows = [['Назва товару', 'Ціна товару', 'Податкова група', 'Артикул', 'Штрихкод'],
                 ['Мед "липовий", 0.5 л', '250.00', '2', '007', '4820000000013']];
        $dir = BOFU_ROOT . '/storage';

        /*
         * XLSX ми складаємо, але більше НЕ читаємо.
         *
         * Читання означало власний розпакувальник ZIP і gzinflate() над
         * завантаженим файлом: вісім дозволених мегабайт архіву розгортаються в
         * гігабайти, і сайт лягає від одного натискання «Завантажити». Кабінет
         * «Вчасно.Каси» вміє віддавати те саме в CSV, тож парсер бінарного
         * формату заради дії раз на квартал прибрано разом із ризиком.
         *
         * Перевіряємо обидві половини: книга й далі складається (її чекає
         * кабінет), а спроба прочитати її назад дає не мовчазну порожнечу, а
         * зрозуміле пояснення, що робити.
         */
        $xlsx = $dir . '/test-sheet.xlsx';
        file_put_contents($xlsx, Sheet::writeXlsx($rows));
        $this->ok('xlsx і далі складається', filesize($xlsx) > 500);
        $this->ok('це справді zip-книга', Sheet::isXlsx($xlsx));
        $this->ok('назад його не читаємо', Sheet::read($xlsx) === []);
        $said = VchasnoGoods::parse($xlsx);
        $this->ok('людині пояснено, що потрібен CSV', str_contains($said['error'], 'CSV'));
        @unlink($xlsx);

        $csv = $dir . '/test-sheet.csv';
        file_put_contents($csv, Sheet::writeCsv($rows));
        $backCsv = Sheet::read($csv);
        @unlink($csv);
        $this->ok('csv читається назад тим самим', $backCsv === $rows);
        $this->ok('нуль попереду артикула вцілів', ($backCsv[1][3] ?? '') === '007');

        // Файл із кабінету: шапка не в першому рядку, підписи інші, кодування win-1251
        $their = "Вивантаження товарів\n\nНазва;Ціна, грн;Штрих-код;Податкова група\nМед;120,50;4820000000013;Без ПДВ\n";
        $win = $dir . '/test-their.csv';
        file_put_contents($win, mb_convert_encoding($their, 'Windows-1251', 'UTF-8'));
        $parsed = VchasnoGoods::parse($win);
        @unlink($win);

        $this->ok('шапка знайшлася не в першому рядку', $parsed['error'] === '');
        $this->ok('позицію прочитано', count($parsed['goods']) === 1);
        $this->ok('win-1251 не зіпсував назву', ($parsed['goods'][0]['name'] ?? '') === 'Мед');
        $this->ok('кома як десятковий знак', abs(($parsed['goods'][0]['price'] ?? 0) - 120.50) < 0.005);
        $this->ok('податкова група словами розпізналась', ($parsed['goods'][0]['taxgrp'] ?? 0) === 2);
    }
}

return (new VchasnoTest())->run();
