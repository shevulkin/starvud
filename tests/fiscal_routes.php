<?php
/**
 * Маршрути до каси: черга, агент, пристрій.  Запуск: php bin/cli.php test
 *
 * Тут не підмінений транспорт, а СПРАВЖНІЙ HTTP: набір піднімає емулятор
 * Device Manager (bin/dm-emulator.php) і ганяє через нього ті самі тіла
 * запитів, які підуть на живу касу. Емулятор перевіряє те, що ми можемо
 * зіпсувати, — сума чека проти суми рядків, оплати проти округлення,
 * податкові групи, абетку назв — і відмовляє тими самими кодами, що описані
 * в їхній документації.
 *
 * Головне, що тут доводиться:
 *   1) коли ключ лежить у магазині, наш сервер до каси НЕ ХОДИТЬ: чек стає в
 *      чергу, і це нормальний стан, а не помилка;
 *   2) завдання забирає той, кому воно адресоване, і рівно один раз;
 *   3) повтор із тією самою міткою не створює другого чека — на цьому тримається
 *      вся обробка обірваного звʼязку;
 *   4) завислі завдання повертаються в чергу самі;
 *   5) повернення йде тим самим маршрутом і до того самого постачальника, що й
 *      продаж, — навіть якщо налаштування відтоді змінили.
 */
declare(strict_types=1);

final class FiscalRoutesTest
{
    private int $pass = 0;
    private int $fail = 0;
    private int $store = 0;
    private int $user = 0;
    private int $product = 0;
    private array $parentIds = [];
    private array $settingsWas = [];
    private array $storeWas = [];
    /** @var resource|null */
    private $proc = null;
    private array $pipes = [];
    private string $dmUrl = '';
    private string $dmState = '';

    public function run(): int
    {
        $stores = Catalog::stores();
        if (!$stores) { echo "  — немає активних магазинів, пропускаємо\n"; return 0; }
        $this->store = (int)$stores[0]['id'];

        if (!$this->startEmulator()) {
            echo "  — не вдалося підняти емулятор Device Manager, пропускаємо\n";
            return 0;
        }
        $this->setUp();
        try {
            $this->testQueued();
            $this->testTakenOnce();
            $this->testAgentRoundTrip();
            $this->testSameTagNoSecondReceipt();
            $this->testEmulatorCatchesBadSum();
            $this->testNamesAreClean();
            $this->testServiceTask();
            $this->testRequeueStale();
            $this->testRefundKeepsRoute();
            $this->testDeviceJobsAreOwn();
        } finally {
            $this->tearDown();
        }
        echo "\n" . ($this->fail === 0
            ? "УСЕ ДОБРЕ: {$this->pass} перевірок\n"
            : "ПРОВАЛЕНО: {$this->fail} з " . ($this->pass + $this->fail) . "\n");
        return $this->fail === 0 ? 0 : 1;
    }

    // ─────────────────────────────────────────────────────────────── емулятор

    private function startEmulator(): bool
    {
        $port = random_int(38100, 38900);
        $this->dmUrl = 'http://127.0.0.1:' . $port;
        $this->dmState = sys_get_temp_dir() . '/dm-test-' . getmypid() . '.json';
        @unlink($this->dmState);

        $cmd = [PHP_BINARY, '-S', '127.0.0.1:' . $port, BOFU_ROOT . '/bin/dm-emulator.php'];
        // Середовище передаємо ЦІЛКОМ, а не двома потрібними змінними: на
        // Windows без SystemRoot вбудований сервер не піднімає сокет і падає
        // з «Failed to listen (reason: ?)» — година втраченого часу на рівному
        // місці, якщо про це не знати.
        $env = getenv();
        $env['DM_STATE'] = $this->dmState;
        $env['DM_DEVICES'] = 'kasa1:1,test1:0';
        $this->proc = @proc_open($cmd, [1 => ['file', $this->dmState . '.log', 'a'],
                                        2 => ['file', $this->dmState . '.log', 'a']],
                                 $this->pipes, BOFU_ROOT, $env);
        if (!is_resource($this->proc)) return false;

        // Чекаємо, поки вбудований сервер справді почне приймати зʼєднання:
        // без цього перший же тест ловив би «connection refused» на швидкій машині
        for ($i = 0; $i < 50; $i++) {
            $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($fp) { fclose($fp); return true; }
            usleep(100000);
        }
        return false;
    }

    /** Той самий шлях, яким піде агент або браузер: HTTP на адресу з завдання */
    private function toKasa(array $job): array
    {
        $ch = curl_init((string)$job['url']);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($job['body'], JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => (int)($job['timeout'] ?? 25),
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
        $json = json_decode((string)$raw, true);
        return is_array($json) ? $json : [];
    }

    // ─────────────────────────────────────────────────────────────── оснастка

    private function setUp(): void
    {
        foreach (['notify_all_enabled', 'vchasno_token', 'fiscal_route', 'fiscal_provider',
                  'vchasno_taxgrp', 'vchasno_send_link', 'vchasno_cash_round'] as $k) {
            $this->settingsWas[$k] = Settings::get($k, null);
        }
        Settings::set('notify_all_enabled', '0');
        Settings::set('vchasno_token', '');       // хмари немає — саме це нам і потрібно
        Settings::set('fiscal_route', 'cloud');
        Settings::set('fiscal_provider', 'vchasno');
        Settings::set('vchasno_taxgrp', '2');
        Settings::set('vchasno_send_link', '0');
        Settings::set('vchasno_cash_round', '1');

        $this->storeWas = (array)DB::row('SELECT fiscal_route, dm_url, dm_device, agent_hash FROM stores WHERE id = ?',
                                         [$this->store]);
        DB::update('stores', ['fiscal_route' => 'agent', 'dm_url' => $this->dmUrl,
                              'dm_device' => 'kasa1'], 'id = ?', [$this->store]);

        $this->user = DB::insert('users', [
            'email' => 'kasa-test-' . bin2hex(random_bytes(4)) . '@bofu.local',
            'name' => 'Тестовий касир', 'role' => 'seller', 'active' => 1,
            'phone' => '+38067' . random_int(1000000, 9999999), 'created_at' => now(),
        ]);

        $cat = (int)(DB::val('SELECT id FROM categories ORDER BY id LIMIT 1') ?? 0);
        $this->product = DB::insert('products', [
            'category_id' => $cat, 'name' => 'Тест: мед «липовий» 🍯',
            'slug' => 'test-route-' . bin2hex(random_bytes(3)),
            'sku' => 'ROUTE-1', 'barcode' => '4820000005551',
            'base_price' => 33.33, 'active' => 1, 'made_to_order' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::insert('store_stock', ['product_id' => $this->product, 'variant_id' => null,
                                   'store_id' => $this->store, 'qty' => 100]);
    }

    private function tearDown(): void
    {
        foreach ($this->parentIds as $pid) {
            foreach (DB::all('SELECT id FROM orders WHERE parent_id = ? OR id = ?', [$pid, $pid]) as $o) {
                DB::delete('order_items', 'order_id = ?', [(int)$o['id']]);
                DB::delete('fiscal_receipts', 'order_id = ?', [(int)$o['id']]);
            }
            DB::delete('order_events', 'parent_id = ?', [$pid]);
            DB::delete('orders', 'parent_id = ?', [$pid]);
            DB::delete('orders', 'id = ?', [$pid]);
        }
        DB::delete('fiscal_receipts', 'store_id = ? AND order_id = 0', [$this->store]);
        if ($this->product) {
            DB::delete('store_stock', 'product_id = ?', [$this->product]);
            DB::delete('products', 'id = ?', [$this->product]);
        }
        if ($this->user) DB::delete('users', 'id = ?', [$this->user]);
        if ($this->storeWas) DB::update('stores', $this->storeWas, 'id = ?', [$this->store]);
        foreach ($this->settingsWas as $k => $v) Settings::set($k, $v ?? '');

        if (is_resource($this->proc)) {
            proc_terminate($this->proc);
            proc_close($this->proc);
        }
        @unlink($this->dmState);
        @unlink($this->dmState . '.log');
    }

    private function ok(string $what, bool $cond): void
    {
        if ($cond) { $this->pass++; echo "  ok   $what\n"; }
        else { $this->fail++; echo "  FAIL $what\n"; }
    }

    private function group(string $name): void { echo "\n== $name ==\n"; }

    /** Замовлення з каси: головне + частина магазину */
    private function order(int $qty = 3, float $price = 33.33): array
    {
        $p = DB::row('SELECT * FROM products WHERE id = ?', [$this->product]);
        $sum = round($price * $qty, 2);
        $placed = OrderFlow::place([
            'number' => 'RT-' . bin2hex(random_bytes(3)), 'token' => bin2hex(random_bytes(8)),
            'user_id' => null, 'name' => 'Покупець', 'phone' => '', 'email' => null,
            'delivery' => 'pickup', 'city' => null, 'np_office' => null, 'address' => null,
            'comment' => null, 'store_id' => $this->store,
            'source' => 'offline', 'created_by_user_id' => $this->user,
            'status' => 'new', 'promo_code' => null,
            'subtotal' => $sum, 'discount' => 0, 'total' => $sum, 'created_at' => now(),
        ], [['product' => $p, 'variant' => null, 'qty' => $qty, 'price' => $price, 'old' => null, 'sum' => $sum]],
            $this->store);
        $this->parentIds[] = (int)$placed['id'];
        return ['parent' => OrderFlow::order((int)$placed['id']),
                'child' => OrderFlow::order((int)$placed['children'][0]['id'])];
    }

    // ──────────────────────────────────────────────────────────────── перевірки

    private function testQueued(): void
    {
        $this->group('ключ у магазині — наш сервер до каси не ходить');
        $o = $this->order();
        $r = Fiscal::sell($o['child'], $o['parent'], ['pay_type' => 0, 'got' => 200.0], $this->user);

        $this->ok('чек не помилка, а черга', $r['state'] === 'queued' && $r['ok']);
        $this->ok('маршрут записано в сам чек', ($r['receipt']['route'] ?? '') === 'agent');
        $this->ok('постачальника теж записано', ($r['receipt']['provider'] ?? '') === 'vchasno');
        $this->ok('фіскального номера ще немає', ($r['receipt']['fiscal_number'] ?? '') === '');
        $this->ok('другий чек на ту саму частину не створюється',
            !Fiscal::sell($o['child'], $o['parent'], ['pay_type' => 0], $this->user)['ok']);
    }

    private function testTakenOnce(): void
    {
        $this->group('завдання забирають рівно один раз');
        $o = $this->order();
        Fiscal::sell($o['child'], $o['parent'], ['pay_type' => 0], $this->user);

        $first = Fiscal::takeForStore($this->store, 10);
        $second = Fiscal::takeForStore($this->store, 10);
        $this->ok('перший забір щось дав', count($first) >= 1);
        $this->ok('другий забір порожній — завдання вже в роботі', $second === []);
        $mine = null;
        foreach ($first as $j) if ((int)$j['id'] > 0) $mine = $j;
        $this->ok('у завданні є адреса каси', str_starts_with((string)($mine['url'] ?? ''), $this->dmUrl));
        $this->ok('у тілі названо касу в DM', ($mine['body']['device'] ?? '') === 'kasa1');
        $this->ok('тип завдання — «виконати»', (int)($mine['body']['type'] ?? 0) === 1);
    }

    private function testAgentRoundTrip(): void
    {
        $this->group('повний шлях: черга → каса → назад');
        $o = $this->order();
        $r = Fiscal::sell($o['child'], $o['parent'], ['pay_type' => 0, 'got' => 500.0], $this->user);
        $id = (int)$r['receipt']['id'];

        $jobs = Fiscal::takeForStore($this->store, 10);
        $job = null;
        foreach ($jobs as $j) if ((int)$j['id'] === $id) $job = $j;
        $this->ok('наше завдання у видачі', $job !== null);

        $answer = $this->toKasa($job);
        $this->ok('каса прийняла чек', (int)($answer['res'] ?? -1) === 0);

        $applied = Fiscal::applyRaw($id, $answer, $this->user);
        $fresh = Fiscal::byId($id);
        $this->ok('чек зараховано', $applied['state'] === 'done' && $fresh['status'] === 'done');
        $this->ok('фіскальний номер збережено', (string)$fresh['fiscal_number'] !== '');
        $this->ok('номер зміни збережено', (int)$fresh['shift_link'] > 0);
        $this->ok('решту порахували від округленої суми', abs((float)$fresh['change'] - 400.0) < 0.005);
        $this->ok('подія потрапила в історію замовлення',
            (bool)DB::val("SELECT 1 FROM order_events WHERE parent_id = ? AND type = 'fiscal'",
                          [(int)$o['parent']['id']]));
    }

    private function testSameTagNoSecondReceipt(): void
    {
        $this->group('повтор тією самою міткою не робить другого чека');
        $o = $this->order();
        $r = Fiscal::sell($o['child'], $o['parent'], ['pay_type' => 2], $this->user);
        $id = (int)$r['receipt']['id'];
        $job = null;
        foreach (Fiscal::takeForStore($this->store, 10) as $j) if ((int)$j['id'] === $id) $job = $j;

        $first = $this->toKasa($job);
        $again = $this->toKasa($job);          // рівно те саме тіло, як після обриву
        $this->ok('обидві спроби вдалі', (int)($first['res'] ?? -1) === 0 && (int)($again['res'] ?? -1) === 0);
        $this->ok('каса віддала ТОЙ САМИЙ чек',
            ($first['info']['doccode'] ?? 'a') === ($again['info']['doccode'] ?? 'b'));
        $this->ok('і той самий номер у зміні',
            ($first['info']['docno'] ?? -1) === ($again['info']['docno'] ?? -2));

        Fiscal::applyRaw($id, $again, $this->user);
        $this->ok('у нас теж один чек',
            (int)DB::val('SELECT COUNT(*) FROM fiscal_receipts WHERE order_id = ?', [(int)$o['child']['id']]) === 1);
    }

    private function testEmulatorCatchesBadSum(): void
    {
        $this->group('каса ловить розбіжність сум — а наш чек проходить');
        $o = $this->order();
        $r = Fiscal::sell($o['child'], $o['parent'], ['pay_type' => 2], $this->user);
        $id = (int)$r['receipt']['id'];
        $job = null;
        foreach (Fiscal::takeForStore($this->store, 10) as $j) if ((int)$j['id'] === $id) $job = $j;

        // Псуємо суму навмисно: якщо каса цього НЕ помітить, набір нічого не доводить
        $broken = $job;
        $broken['body']['fiscal']['receipt']['sum'] += 0.50;
        $broken['body']['tag'] = 'broken-' . bin2hex(random_bytes(4));
        $bad = $this->toKasa($broken);
        $this->ok('зіпсовану суму каса відхиляє', (int)($bad['res'] ?? 0) === 1001);
        $this->ok('і пояснює числами', isset($bad['error_extra']['rows_sum']));

        $good = $this->toKasa($job);
        $this->ok('наша власна сума проходить', (int)($good['res'] ?? -1) === 0);
        Fiscal::applyRaw($id, $good, $this->user);

        // А відмову ми записуємо як помилку, не як «невідомо»
        $o2 = $this->order();
        $r2 = Fiscal::sell($o2['child'], $o2['parent'], ['pay_type' => 2], $this->user);
        $id2 = (int)$r2['receipt']['id'];
        Fiscal::applyRaw($id2, $bad, $this->user);
        $f2 = Fiscal::byId($id2);
        $this->ok('відмова каси — це error, а не pending', $f2['status'] === 'error');
        $this->ok('причину видно з деталями', str_contains((string)$f2['error'], 'rows_sum'));
    }

    private function testNamesAreClean(): void
    {
        $this->group('назви доходять до каси без сміття');
        // У товару в назві емодзі та лапки-ялинки: каса замінює недозволене на «?»,
        // тож знак питання у відповіді означав би, що ми не почистили назву самі
        $o = $this->order(1, 50.0);
        $r = Fiscal::sell($o['child'], $o['parent'], ['pay_type' => 2], $this->user);
        $id = (int)$r['receipt']['id'];
        $job = null;
        foreach (Fiscal::takeForStore($this->store, 10) as $j) if ((int)$j['id'] === $id) $job = $j;

        $name = (string)($job['body']['fiscal']['receipt']['rows'][0]['name'] ?? '');
        $this->ok('емодзі прибрано ще в нас', !str_contains($name, '🍯'));
        $this->ok('українські лапки лишились', str_contains($name, '«') && str_contains($name, '»'));
        $this->ok('назва не порожня', trim($name) !== '');
        $this->ok('знаків питання в назві немає', !str_contains($name, '?'));

        $answer = $this->toKasa($job);
        $this->ok('каса прийняла назву', (int)($answer['res'] ?? -1) === 0);
        Fiscal::applyRaw($id, $answer, $this->user);
    }

    private function testServiceTask(): void
    {
        $this->group('службові завдання йдуть тією самою чергою');
        $r = Fiscal::service('shift_close', $this->store, $this->user, ['cashier' => 'Тест']);
        $this->ok('Z-звіт став у чергу, а не «виконано»', $r['state'] === 'queued');
        $id = (int)$r['receipt']['id'];

        $job = null;
        foreach (Fiscal::takeForStore($this->store, 10) as $j) if ((int)$j['id'] === $id) $job = $j;
        $this->ok('агент отримує і його теж', $job !== null);
        $this->ok('це завдання закриття зміни',
            (int)($job['body']['fiscal']['task'] ?? -1) === Vchasno::TASK_Z_REPORT);

        $answer = $this->toKasa($job);
        Fiscal::applyRaw($id, $answer, $this->user);
        $fresh = Fiscal::byId($id);
        $this->ok('виконане службове завдання — done', $fresh['status'] === 'done');
        $this->ok('відповідь каси збережено', str_contains((string)$fresh['result'], 'shift_link'));
        $this->ok('чеком воно не прикидається', (string)$fresh['fiscal_number'] === '');
        $this->ok('до замовлення не привʼязане', (int)$fresh['order_id'] === 0);
    }

    private function testRequeueStale(): void
    {
        $this->group('завдання, яке агент забрав і не повернув, вертається в чергу');
        $o = $this->order();
        $r = Fiscal::sell($o['child'], $o['parent'], ['pay_type' => 2], $this->user);
        $id = (int)$r['receipt']['id'];
        Fiscal::takeForStore($this->store, 10);
        $this->ok('після забору чек «надіслано»', (Fiscal::byId($id)['status'] ?? '') === 'pending');

        // Вдаємо, що агент замовк давно
        DB::update('fiscal_receipts', ['updated_at' => date('Y-m-d H:i:s', time() - 3600)], 'id = ?', [$id]);
        $back = Fiscal::requeueStale();
        $this->ok('щось повернулось у чергу', $back >= 1);
        $this->ok('саме наш чек', (Fiscal::byId($id)['status'] ?? '') === 'queued');
        $this->ok('мітка не змінилась — другого чека не буде',
            (string)Fiscal::byId($id)['tag'] === (string)$r['receipt']['tag']);
    }

    private function testRefundKeepsRoute(): void
    {
        $this->group('повернення йде туди ж, куди пішов продаж');
        $o = $this->order();
        $r = Fiscal::sell($o['child'], $o['parent'], ['pay_type' => 2], $this->user);
        $id = (int)$r['receipt']['id'];
        $job = null;
        foreach (Fiscal::takeForStore($this->store, 10) as $j) if ((int)$j['id'] === $id) $job = $j;
        Fiscal::applyRaw($id, $this->toKasa($job), $this->user);
        $sale = Fiscal::byId($id);

        // Постачальника й маршрут відтоді змінили — повернення це не має зачепити
        DB::update('stores', ['fiscal_route' => 'cloud'], 'id = ?', [$this->store]);
        $back = Fiscal::refund($sale, $this->user, 'Тест');
        DB::update('stores', ['fiscal_route' => 'agent'], 'id = ?', [$this->store]);

        $this->ok('повернення стало в чергу тим самим маршрутом',
            $back['state'] === 'queued' && ($back['receipt']['route'] ?? '') === 'agent');
        $this->ok('постачальник теж той самий', ($back['receipt']['provider'] ?? '') === 'vchasno');
        $this->ok('привʼязане до чека продажу', (int)($back['receipt']['of_receipt_id'] ?? 0) === $id);

        $rid = (int)$back['receipt']['id'];
        $rjob = null;
        foreach (Fiscal::takeForStore($this->store, 10) as $j) if ((int)$j['id'] === $rid) $rjob = $j;
        $this->ok('це завдання повернення',
            (int)($rjob['body']['fiscal']['task'] ?? -1) === Vchasno::TASK_RETURN);
        $this->ok('рядки дзеркалять продаж',
            json_encode($rjob['body']['fiscal']['receipt']['rows'])
            === json_encode($job['body']['fiscal']['receipt']['rows']));

        Fiscal::applyRaw($rid, $this->toKasa($rjob), $this->user);
        $this->ok('повернення проведено', (Fiscal::byId($rid)['status'] ?? '') === 'done');
        $this->ok('удруге те саме не повернемо', !Fiscal::refund($sale, $this->user, 'Тест')['ok']);
    }

    private function testDeviceJobsAreOwn(): void
    {
        $this->group('каса на пристрої: завдання бачить лише свій продавець');
        DB::update('users', ['fiscal_route' => 'device', 'dm_url' => $this->dmUrl,
                             'dm_device' => 'test1'], 'id = ?', [$this->user]);
        $o = $this->order();
        $r = Fiscal::sell($o['child'], $o['parent'], ['pay_type' => 2], $this->user);
        $id = (int)$r['receipt']['id'];

        $this->ok('маршрут людини переважив маршрут точки', ($r['receipt']['route'] ?? '') === 'device');
        $this->ok('агент точки такого завдання не бачить',
            !in_array($id, array_map(fn($j) => (int)$j['id'], Fiscal::takeForStore($this->store, 10)), true));

        $mine = array_map(fn($x) => (int)$x['id'], Fiscal::queuedForUser($this->user, (int)$o['parent']['id']));
        $this->ok('а його продавець — бачить', in_array($id, $mine, true));
        $this->ok('чужий продавець — ні', Fiscal::queuedForUser($this->user + 999999) === []);

        $job = Fiscal::job(Fiscal::byId($id));
        $this->ok('завдання адресоване касі з профілю', ($job['body']['device'] ?? '') === 'test1');
        $answer = $this->toKasa($job);
        Fiscal::applyRaw($id, $answer, $this->user);
        $fresh = Fiscal::byId($id);
        $this->ok('чек пробито', $fresh['status'] === 'done');
        $this->ok('тестова каса позначена як тестова', (int)$fresh['is_test'] === 1);

        DB::update('users', ['fiscal_route' => null, 'dm_url' => null, 'dm_device' => null],
                   'id = ?', [$this->user]);
    }
}

return (new FiscalRoutesTest())->run();
