<?php
/**
 * ПРРО ДПС: документи, підпис у браузері, фіскальний сервер.  Запуск: php bin/cli.php test
 *
 * Ключа тут немає й бути не може — тож «підпис» у тестах тотожний: браузер
 * повертає ті самі байти. Усе інше справжнє:
 *   — XML чеків і Z-звіту перевіряється офіційними схемами ДПС (tests/fixtures/dps);
 *   — фіскальний сервер підмінено імітацією, яка веде зміну й наскрізну
 *     нумерацію так само суворо, як справжній: чужий локальний номер — код 7,
 *     чек без зміни — код 5;
 *   — автомат DpsFlow проходить ті самі кроки, що й з живою вкладкою.
 *
 * Головне, що доводиться:
 *   1) закрита зміна відкривається сама перед першим чеком;
 *   2) обірваний звʼязок після відправки НЕ дає другого чека: спершу питаємо
 *      ДПС за локальним номером, і лише не знайшовши — шлемо ще раз;
 *   3) розійшлась нумерація (чек пробили з іншого пристрою) — звіряємось і
 *      складаємо документ заново з правильним номером;
 *   4) Z-звіт будується з підсумків сервера й проходить схему;
 *   5) тестова й робоча зміни не змішуються.
 */
declare(strict_types=1);

final class DpsTest
{
    private int $pass = 0;
    private int $fail = 0;
    private int $store = 0;
    private int $owner = 0;
    private int $user = 0;
    private int $parent = 0;
    private int $child = 0;
    private array $settingsWas = [];
    /** Імітація фіскального сервера */
    private array $fs = [];

    public function run(): int
    {
        $this->setUp();
        try {
            $this->testXmlSale();
            $this->testXmlVatAndCard();
            $this->testXmlServiceAndShift();
            $this->testXmlZrep();
            $this->testTicketAndErrors();
            $this->testCms();
            $this->testProxyHosts();
            $this->testFlowSale();
            $this->testFlowLostAnswerFound();
            $this->testFlowLostAnswerNotFound();
            $this->testFlowNumberDrift();
            $this->testFlowRefused();
            $this->testFlowZReport();
            $this->testFlowTestingMismatch();
            $this->testFlowBusyAndOwnership();
        } finally {
            $this->tearDown();
        }
        echo "\n" . ($this->fail === 0
            ? "УСЕ ДОБРЕ: {$this->pass} перевірок\n"
            : "ПРОВАЛЕНО: {$this->fail} з " . ($this->pass + $this->fail) . "\n");
        return $this->fail === 0 ? 0 : 1;
    }

    // ─────────────────────────────────────────────────────────────── XML

    private function ctx(bool $vat = false, bool $testing = true): array
    {
        return ['ok' => true, 'missing' => [], 'tin' => '2644016419', 'orgnm' => 'ФОП Тестовий Тест Тестович',
                'pointnm' => 'Інтернет-магазин «Тест»', 'pointaddr' => 'м. Київ, вул. Тестова, 1',
                'cashdesk' => 1, 'rro' => '4000123456', 'vat' => $vat, 'testing' => $testing];
    }

    private function saleDoc(): array
    {
        return [
            'task' => 'sell', 'cashier' => 'Головецький І. І.',
            'sum' => 412.57, 'round' => 0.03,
            'rows' => [
                ['name' => 'Пазл «Щенячий патрульʼ» 4 в 1 🧩', 'cnt' => 2, 'price' => 150.00, 'disc' => 10.00,
                 'taxgrp' => 2, 'code' => 'PZL-01', 'code1' => '4820000000017', 'code2' => '9503009500'],
                ['name' => 'Брязкальце', 'cnt' => 1, 'price' => 122.57, 'disc' => 0, 'taxgrp' => 2],
            ],
            'pays' => [['type' => 0, 'sum' => 412.60, 'change' => 87.40]],
        ];
    }

    private function valid(string $xml, string $xsd): bool
    {
        $d = new DOMDocument();
        $d->loadXML($xml);
        $prev = libxml_use_internal_errors(true);
        $ok = $d->schemaValidate(BOFU_ROOT . '/tests/fixtures/dps/' . $xsd);
        foreach (libxml_get_errors() as $e) echo '       xsd: ' . trim($e->message) . "\n";
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return $ok;
    }

    private function testXmlSale(): void
    {
        $this->group('чек продажу: схема, суми, кодування');
        $xml = DpsDoc::check($this->saleDoc(), $this->ctx(), ['num' => 12, 'dt' => mktime(14, 5, 9, 9, 29, 2026), 'uid' => DpsDoc::uid()]);
        $this->ok('проходить офіційну схему check01.xsd', $this->valid($xml, 'check01.xsd'));
        $this->ok('кодування — windows-1251', str_contains(substr($xml, 0, 60), 'windows-1251'));
        $u = DpsDoc::toUtf8($xml);
        $this->ok('апостроф ʼ замінено звичайним', str_contains($u, "патруль'") && !str_contains($u, '&#'));
        $this->ok('емодзі прибрано з назви', !str_contains($u, '🧩'));
        $this->ok('вартість рядка — до знижки', str_contains($u, '<COST>300.00</COST>'));
        $this->ok('знижка рядка окремим полем', str_contains($u, '<DISCOUNTTYPE>0</DISCOUNTTYPE><DISCOUNTSUM>10.00</DISCOUNTSUM>'));
        $this->ok('сума до сплати — з округленням', str_contains($u, '<CHECKTOTAL><SUM>412.60</SUM>'));
        $this->ok('заокруглення зі знаком ДПС (−0.03)', str_contains($u, '<RNDSUM>-0.03</RNDSUM><NORNDSUM>412.57</NORNDSUM>'));
        $this->ok('загальна знижка', str_contains($u, '<DISCOUNTSUM>10.00</DISCOUNTSUM></CHECKTOTAL>'));
        $this->ok('решта готівкою', str_contains($u, '<PROVIDED>500.00</PROVIDED><REMAINS>87.40</REMAINS>'));
        $this->ok('неплатник ПДВ — без податків і літер', !str_contains($u, 'CHECKTAX') && !str_contains($u, 'LETTERS'));
        $this->ok('УКТЗЕД і штрихкод на місці', str_contains($u, '<BARCODE>4820000000017</BARCODE><UKTZED>9503009500</UKTZED>'));
        $this->ok('тестовий документ позначено', str_contains($u, '<TESTING>true</TESTING>'));
        $this->ok('дата й час у форматі ДПС', str_contains($u, '<ORDERDATE>29092026</ORDERDATE><ORDERTIME>140509</ORDERTIME>'));

        $ret = DpsDoc::check(['task' => 'return'] + $this->saleDoc(), $this->ctx(false, false), ['num' => 13, 'dt' => time(), 'ret' => '123456789']);
        $this->ok('повернення проходить схему', $this->valid($ret, 'check01.xsd'));
        $ur = DpsDoc::toUtf8($ret);
        $this->ok('повернення: DOCSUBTYPE 1 і номер чека продажу', str_contains($ur, '<DOCSUBTYPE>1</DOCSUBTYPE>') && str_contains($ur, '<ORDERRETNUM>123456789</ORDERRETNUM>'));
        $this->ok('робочий режим — без TESTING', !str_contains($ur, 'TESTING'));
    }

    private function testXmlVatAndCard(): void
    {
        $this->group('платник ПДВ і картка');
        $doc = ['task' => 'sell', 'cashier' => 'Касир', 'sum' => 240.00, 'round' => 0,
                'rows' => [['name' => 'Лялька', 'cnt' => 1, 'price' => 120, 'disc' => 0, 'taxgrp' => 1],
                           ['name' => 'Книжка', 'cnt' => 1, 'price' => 120, 'disc' => 0, 'taxgrp' => 4]],
                'pays' => [['type' => 2, 'sum' => 240.00]]];
        $xml = DpsDoc::check($doc, $this->ctx(true), ['num' => 1, 'dt' => time()]);
        $this->ok('проходить схему', $this->valid($xml, 'check01.xsd'));
        $u = DpsDoc::toUtf8($xml);
        $this->ok('картка — форма оплати 1', str_contains($u, '<PAYFORMCD>1</PAYFORMCD>'));
        $this->ok('ПДВ 20% літерою А: 120 → 20.00', str_contains($u, '<LETTER>А</LETTER><PRC>20.00</PRC>') && str_contains($u, '<SUM>20.00</SUM>'));
        $this->ok('ПДВ 7% літерою Б: 120 → 7.85', str_contains($u, '<LETTER>Б</LETTER><PRC>7.00</PRC>') && str_contains($u, '<SUM>7.85</SUM>'));
        $bad = false;
        try { DpsDoc::check(['pays' => [['type' => 1, 'sum' => 10]]] + $doc, $this->ctx(), ['num' => 1, 'dt' => time()]); }
        catch (RuntimeException $e) { $bad = str_contains($e->getMessage(), 'рахунок'); }
        $this->ok('оплата на рахунок — зрозуміла відмова', $bad);
    }

    private function testXmlServiceAndShift(): void
    {
        $this->group('службові документи');
        foreach (['cash_in' => 2, 'cash_out' => 4] as $task => $sub) {
            $xml = DpsDoc::check(['task' => $task, 'cashier' => 'Касир', 'cash' => ['sum' => 500]], $this->ctx(), ['num' => 3, 'dt' => time()]);
            $this->ok("$task проходить схему", $this->valid($xml, 'check01.xsd'));
            $this->ok("$task: DOCSUBTYPE $sub і сума", str_contains(DpsDoc::toUtf8($xml), "<DOCSUBTYPE>$sub</DOCSUBTYPE>") && str_contains($xml, '<SUM>500.00</SUM>'));
        }
        $open = DpsDoc::shift(true, $this->ctx(), ['num' => 1, 'dt' => time()], 'Касир');
        $close = DpsDoc::shift(false, $this->ctx(), ['num' => 9, 'dt' => time()], 'Касир');
        $this->ok('відкриття зміни (100) проходить схему', $this->valid($open, 'check01.xsd') && str_contains($open, '<DOCTYPE>100</DOCTYPE>'));
        $this->ok('закриття зміни (101) проходить схему', $this->valid($close, 'check01.xsd') && str_contains($close, '<DOCTYPE>101</DOCTYPE>'));
    }

    private function totals(): array
    {
        return [
            'Real' => ['Sum' => 1250.50, 'OrdersCount' => 4, 'RndSum' => 0.03, 'NoRndSum' => 1250.53,
                       'PayForm' => [['PayFormCode' => 0, 'PayFormName' => 'ГОТІВКА', 'Sum' => 850.50],
                                     ['PayFormCode' => 1, 'PayFormName' => 'БАНКІВСЬКА КАРТКА', 'Sum' => 400.00]],
                       'Tax' => []],
            'Ret' => ['Sum' => 150.00, 'OrdersCount' => 1,
                      'PayForm' => [['PayFormCode' => 0, 'PayFormName' => 'ГОТІВКА', 'Sum' => 150.00]]],
            'ServiceInput' => 500.00, 'ServiceOutput' => 0,
        ];
    }

    private function testXmlZrep(): void
    {
        $this->group('Z-звіт');
        $xml = DpsDoc::zrep($this->totals(), $this->ctx(), ['num' => 20, 'dt' => time()], 'Касир');
        $this->ok('проходить офіційну схему zrep01.xsd', $this->valid($xml, 'zrep01.xsd'));
        $u = DpsDoc::toUtf8($xml);
        $this->ok('виторг і кількість чеків', str_contains($u, '<ZREPREALIZ><SUM>1250.50</SUM>') && str_contains($u, '<ORDERSCNT>4</ORDERSCNT>'));
        $this->ok('повернення окремим розділом', str_contains($u, '<ZREPRETURN><SUM>150.00</SUM>'));
        $this->ok('службове внесення', str_contains($u, '<SERVICEINPUT>500.00</SERVICEINPUT>'));
        $empty = DpsDoc::zrep([], $this->ctx(), ['num' => 21, 'dt' => time()], 'Касир');
        $this->ok('порожня зміна теж дає коректний звіт', $this->valid($empty, 'zrep01.xsd'));
    }

    private function testTicketAndErrors(): void
    {
        $this->group('квитанція й помилки сервера');
        $t = DpsDoc::ticket($this->ticket(0, '', 12, '123456789'));
        $this->ok('успіх: фіскальний номер', $t['ok'] && $t['taxnum'] === '123456789' && $t['num'] === '12');
        $t = DpsDoc::ticket($this->ticket(7, 'Некоректний локальний номер чека', 12, ''));
        $this->ok('відмова: код і текст кирилицею', !$t['ok'] && $t['code'] === 7 && str_contains($t['text'], 'локальний'));
        $e = DpsDoc::errorText(mb_convert_encoding("Код помилки: 9 DocumentValidationError\r\nНевірна сума чека", 'Windows-1251', 'UTF-8'));
        $this->ok('текстова помилка HTTP розбирається', $e['code'] === 9 && str_contains($e['text'], 'сума'));
        $url = DpsDoc::checkUrl('123456789', '4000123456', mktime(14, 5, 0, 9, 29, 2026), 412.6);
        $this->ok('посилання на чек у кабінеті ДПС', $url === 'https://cabinet.tax.gov.ua/cashregs/check?id=123456789&fn=4000123456&date=20260929&time=1405&sm=412.60');
    }

    /** Мінімальний CMS SignedData з eContent — як загортає відповідь сервер */
    private function cms(string $content, bool $chunked = false): string
    {
        $len = function (int $n): string {
            if ($n < 128) return chr($n);
            $b = ltrim(pack('N', $n), "\0");
            return chr(0x80 | strlen($b)) . $b;
        };
        $t = fn(int $tag, string $v) => chr($tag) . $len(strlen($v)) . $v;
        $oidData = $t(0x06, "\x2A\x86\x48\x86\xF7\x0D\x01\x07\x01");
        $oidSigned = $t(0x06, "\x2A\x86\x48\x86\xF7\x0D\x01\x07\x02");
        $oct = $chunked
            ? chr(0x24) . "\x80" . $t(0x04, substr($content, 0, 5)) . $t(0x04, substr($content, 5)) . "\0\0"
            : $t(0x04, $content);
        $eci = $t(0x30, $oidData . $t(0xA0, $oct));
        $sd = $t(0x30, $t(0x02, "\x01") . $t(0x31, '') . $eci . $t(0x31, ''));
        return $t(0x30, $oidSigned . $t(0xA0, $sd));
    }

    private function testCms(): void
    {
        $this->group('конверт CMS');
        $xml = $this->ticket(0, '', 5, '777');
        $this->ok('вміст дістається з підписаного повідомлення', Dps::content($this->cms($xml)) === $xml);
        $this->ok('і зі складеного шматками (BER)', Dps::content($this->cms($xml, true)) === $xml);
        $this->ok('не CMS — повертається як є', Dps::content('{"a":1}') === '{"a":1}');
    }

    private function testProxyHosts(): void
    {
        $this->group('проксі до ЦСК');
        $hosts = Dps::caHosts();
        $this->ok('КНЕДП ДПС у білому списку', isset($hosts['ca.tax.gov.ua']));
        $this->ok('ПриватБанк у білому списку', isset($hosts['acsk.privatbank.ua']));
        $this->ok('кореневий орган — теж', isset($hosts['czo.gov.ua']));
        $this->ok('чужий хост — ні', !isset($hosts[Dps::caHost('evil.example.com/x')]));
        $this->ok('адреса з логіном не проходить', Dps::caHost('http://u:p@ca.tax.gov.ua/') === '');
        $this->ok('чужа схема не проходить', Dps::caHost('file:///etc/passwd') === '');
    }

    // ─────────────────────────────────────────────────── імітація сервера

    private function ticket(int $code, string $text, int $num, string $taxnum): string
    {
        return (string)mb_convert_encoding('<?xml version="1.0" encoding="windows-1251"?><TICKET><UID>X</UID>'
            . '<ORDERDATE>29092026</ORDERDATE><ORDERTIME>140000</ORDERTIME><ORDERNUM>' . $num . '</ORDERNUM>'
            . ($taxnum !== '' ? '<ORDERTAXNUM>' . $taxnum . '</ORDERTAXNUM>' : '')
            . '<ERRORCODE>' . $code . '</ERRORCODE><ERRORTEXT>' . $text . '</ERRORTEXT><VER>1</VER></TICKET>',
            'Windows-1251', 'UTF-8');
    }

    private function fsReset(bool $open = false, int $next = 5, bool $testing = true): void
    {
        $this->fs = ['open' => $open, 'next' => $next, 'testing' => $testing, 'docs' => [], 'fiscal' => 900000,
                     'drop' => 0, 'dropAfter' => 0, 'force' => null, 'sent' => 0, 'xsdBad' => 0];
        DB::update('stores', ['dps_shift_open' => 0, 'dps_next_num' => null, 'dps_synced_at' => null,
                              'dps_testing' => null, 'dps_shift_at' => null], 'id = ?', [$this->store]);
    }

    /** Фіскальний сервер: зміна, наскрізна нумерація, коди відмов */
    private function fake(string $path, string $body, string $type): array
    {
        $fs = &$this->fs;
        if ($path === 'cmd') {
            if ($fs['drop'] > 0) { $fs['drop']--; return ['status' => 0, 'body' => '', 'error' => 'обрив']; }
            $j = json_decode($body, true) ?: [];
            switch ($j['Command'] ?? '') {
                case 'TransactionsRegistrarState':
                    return ['status' => 200, 'error' => '', 'body' => json_encode(['ShiftState' => $fs['open'] ? 1 : 0,
                        'NextLocalNum' => $fs['next'], 'Testing' => $fs['testing']])];
                case 'DocumentInfoByLocalNum':
                    $n = (int)$j['NumLocal'];
                    return isset($fs['docs'][$n])
                        ? ['status' => 200, 'error' => '', 'body' => json_encode(['NumFiscal' => (string)$fs['docs'][$n]])]
                        : ['status' => 204, 'error' => '', 'body' => ''];
                case 'LastShiftTotals':
                    return ['status' => 200, 'error' => '', 'body' => json_encode(['ShiftState' => $fs['open'] ? 1 : 0, 'Totals' => $this->totals()])];
            }
            return ['status' => 400, 'error' => '', 'body' => 'Код помилки: 11 InvalidQueryParameter'];
        }
        // документ
        $fs['sent']++;
        $x = simplexml_load_string($body);
        $isZ = $x && $x->getName() === 'ZREP';
        if (!$this->valid($body, $isZ ? 'zrep01.xsd' : 'check01.xsd')) $fs['xsdBad']++;
        $h = $isZ ? $x->ZREPHEAD : $x->CHECKHEAD;
        $type = $isZ ? -1 : (int)$h->DOCTYPE;
        $num = (int)$h->ORDERNUM;
        if ($fs['force'] !== null) { $c = $fs['force']; $fs['force'] = null; return ['status' => 200, 'error' => '', 'body' => $this->ticket($c[0], $c[1], $num, '')]; }
        if ($num !== $fs['next']) return ['status' => 200, 'error' => '', 'body' => $this->ticket(7, 'Некоректний локальний номер чека', $num, '')];
        if ($type === 100 && $fs['open']) return ['status' => 200, 'error' => '', 'body' => $this->ticket(4, 'Зміну для ПРРО наразі відкрито', $num, '')];
        if ($type !== 100 && !$fs['open']) return ['status' => 200, 'error' => '', 'body' => $this->ticket(5, 'Зміну для ПРРО наразі не відкрито', $num, '')];
        $fiscal = (string)(++$fs['fiscal']);
        $fs['docs'][$num] = $fiscal;
        $fs['next']++;
        if ($type === 100) { $fs['open'] = true; $fs['testing'] = isset($h->TESTING); }
        if ($type === 101) $fs['open'] = false;
        // Обрив ПІСЛЯ реєстрації: документ є, а відповідь загубилась
        if ($fs['dropAfter'] > 0) { $fs['dropAfter']--; return ['status' => 0, 'body' => '', 'error' => 'обрив']; }
        return ['status' => 200, 'error' => '', 'body' => $this->cms($this->ticket(0, '', $num, $fiscal))];
    }

    /** Вкладка: крок → «підпис» (тотожний) → відправка, доки сервер не скаже «готово» */
    private function sign(int $id, string $tab = 'T1', int $max = 12): array
    {
        $r = DpsFlow::step($id, $this->user, $tab);
        $path = [];
        while ($r['action'] === 'sign' && $max-- > 0) {
            $path[] = $r['purpose'];
            $r = DpsFlow::submit($id, $this->user, $tab, $r['purpose'], $r['data']);
        }
        $r['path'] = $path;
        return $r;
    }

    private function receipt(string $task = 'sell', array $doc = []): int
    {
        $doc = $doc ?: $this->saleDoc();
        return (int)DB::insert('fiscal_receipts', [
            'order_id' => $this->child, 'parent_id' => $this->parent, 'store_id' => $this->store,
            'provider' => 'dps', 'route' => 'device', 'type' => $task === 'sell' || $task === 'return' ? $task : 'service',
            'task' => $task, 'tag' => bin2hex(random_bytes(8)), 'doc' => json_encode($doc + ['task' => $task], JSON_UNESCAPED_UNICODE),
            'payload' => '{}', 'status' => 'queued', 'attempts' => 0, 'sum' => 412.60, 'pay_type' => 0,
            'created_by_user_id' => $this->user, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ────────────────────────────────────────────────────────────── автомат

    private function testFlowSale(): void
    {
        $this->group('чек із закритою зміною');
        $this->fsReset(false, 5);
        $id = $this->receipt();
        $this->ok('документ у черзі вкладки', in_array($id, array_map(fn($r) => (int)$r['id'], DpsFlow::jobsFor($this->user, $this->parent)), true));
        $r = $this->sign($id);
        $this->ok('шлях: стан → відкриття зміни → чек', $r['path'] === ['state', 'shift_open', 'doc']);
        $f = Fiscal::byId($id);
        $this->ok('чек фіскалізовано', $f['status'] === 'done' && $r['state'] === 'done');
        $this->ok('фіскальний номер — від сервера', $f['fiscal_number'] === $this->fs['docs'][6]);
        $this->ok('тестовий чек позначено', (int)$f['is_test'] === 1);
        $this->ok('посилання на чек у кабінеті ДПС', str_starts_with((string)$f['qr'], 'https://cabinet.tax.gov.ua/cashregs/check?id=' . $f['fiscal_number']));
        $s = DB::row('SELECT * FROM stores WHERE id = ?', [$this->store]);
        $this->ok('зміна відкрита, наступний номер 7', (int)$s['dps_shift_open'] === 1 && (int)$s['dps_next_num'] === 7);
        $this->ok('усі документи пройшли схему', $this->fs['xsdBad'] === 0);
        $this->ok('черга порожня', DpsFlow::jobsFor($this->user, $this->parent) === []);

        $id2 = $this->receipt();
        $r2 = $this->sign($id2);
        $this->ok('другий чек — без зайвих кроків', $r2['path'] === ['doc'] && $r2['state'] === 'done');
    }

    private function testFlowLostAnswerFound(): void
    {
        $this->group('обрив після відправки: документ дійшов');
        $this->fsReset(true, 30);
        DB::update('stores', ['dps_shift_open' => 1, 'dps_next_num' => 30, 'dps_synced_at' => now(), 'dps_testing' => 1], 'id = ?', [$this->store]);
        $id = $this->receipt();
        $this->fs['dropAfter'] = 1;
        $r = $this->sign($id);
        $this->ok('вкладка чекає звʼязку, а не кричить «помилка»', $r['action'] === 'wait');
        $sent = $this->fs['sent'];
        $r = $this->sign($id);
        $this->ok('наступним кроком — пошук за локальним номером', ($r['path'][0] ?? '') === 'lookup');
        $this->ok('другого чека не відправлено', $this->fs['sent'] === $sent);
        $f = Fiscal::byId($id);
        $this->ok('номер забрано з ДПС', $f['status'] === 'done' && $f['fiscal_number'] === $this->fs['docs'][30]);
        $this->ok('нумерація зсунулась рівно на один', (int)DB::val('SELECT dps_next_num FROM stores WHERE id = ?', [$this->store]) === 31);
    }

    private function testFlowLostAnswerNotFound(): void
    {
        $this->group('обрив до сервера: документ не дійшов');
        $this->fsReset(true, 40);
        DB::update('stores', ['dps_shift_open' => 1, 'dps_next_num' => 40, 'dps_synced_at' => now(), 'dps_testing' => 1], 'id = ?', [$this->store]);
        $id = $this->receipt();
        // обрив на самому документі: сервер його не бачив
        $orig = Dps::$transport;
        $n = 0;
        Dps::$transport = function ($p, $b, $t) use (&$n, $orig) {
            if ($p === 'doc' && $n++ === 0) return ['status' => 0, 'body' => '', 'error' => 'обрив'];
            return $orig($p, $b, $t);
        };
        $r = $this->sign($id);
        $this->ok('чекаємо звʼязку', $r['action'] === 'wait');
        Dps::$transport = $orig;
        $r = $this->sign($id);
        $this->ok('спершу спитали, потім відправили ще раз', $r['path'] === ['lookup', 'doc']);
        $this->ok('чек пробито один раз, з тим самим номером', Fiscal::byId($id)['status'] === 'done' && count($this->fs['docs']) === 1 && isset($this->fs['docs'][40]));
    }

    private function testFlowNumberDrift(): void
    {
        $this->group('нумерація розійшлась (документ з іншого пристрою)');
        $this->fsReset(true, 60);
        // наша копія стану застаріла: думаємо, що наступний — 55
        DB::update('stores', ['dps_shift_open' => 1, 'dps_next_num' => 55, 'dps_synced_at' => now(), 'dps_testing' => 1], 'id = ?', [$this->store]);
        $id = $this->receipt();
        $r = $this->sign($id);
        $this->ok('відмова 7 → звірка → чек з правильним номером', $r['path'] === ['doc', 'state', 'doc'] && $r['state'] === 'done');
        $this->ok('зареєстровано під номером 60', isset($this->fs['docs'][60]));
    }

    private function testFlowRefused(): void
    {
        $this->group('сервер відмовив по суті');
        $this->fsReset(true, 70);
        DB::update('stores', ['dps_shift_open' => 1, 'dps_next_num' => 70, 'dps_synced_at' => now(), 'dps_testing' => 1], 'id = ?', [$this->store]);
        $id = $this->receipt();
        $this->fs['force'] = [9, 'Помилка валідації документа: сума оплат не дорівнює сумі чека'];
        $r = $this->sign($id);
        $f = Fiscal::byId($id);
        $this->ok('чек у стані «помилка» з текстом сервера', $f['status'] === 'error' && str_contains((string)$f['error'], 'сума оплат'));
        $this->ok('вкладка отримала «готово з помилкою»', $r['action'] === 'done' && $r['state'] === 'error');
        $this->ok('нумерація не зсунулась', (int)DB::val('SELECT dps_next_num FROM stores WHERE id = ?', [$this->store]) === 70);
    }

    private function testFlowZReport(): void
    {
        $this->group('Z-звіт і закриття зміни');
        $this->fsReset(true, 80);
        DB::update('stores', ['dps_shift_open' => 1, 'dps_next_num' => 80, 'dps_synced_at' => now(), 'dps_testing' => 1], 'id = ?', [$this->store]);
        $prov = Settings::get('fiscal_provider');
        $r0 = Fiscal::service('shift_close', $this->store, null, ['cashier' => 'Касир']);
        $this->ok('Z-звіт стає в чергу (ключ у касира)', $r0['state'] === 'queued');
        $id = (int)$r0['receipt']['id'];
        $this->ok('нічний Z-звіт бачить будь-яка вкладка з ключем', in_array($id, array_map(fn($r) => (int)$r['id'], DpsFlow::jobsFor($this->user)), true));
        $r = $this->sign($id);
        $this->ok('шлях: підсумки → Z-звіт → закриття', $r['path'] === ['totals', 'zrep', 'shift_close'] && $r['state'] === 'done');
        $this->ok('Z-звіт пройшов схему', $this->fs['xsdBad'] === 0);
        $this->ok('зміна закрита', (int)DB::val('SELECT dps_shift_open FROM stores WHERE id = ?', [$this->store]) === 0 && !$this->fs['open']);
        $res = json_decode((string)Fiscal::byId($id)['result'], true);
        $this->ok('номер Z-звіту й підсумки збережено', !empty($res['zrep']) && (float)($res['totals']['Real']['Sum'] ?? 0) === 1250.5);

        $x = Fiscal::service('x_report', $this->store, $this->user, ['cashier' => 'Касир']);
        $r = $this->sign((int)$x['receipt']['id']);
        $this->ok('X-звіт на закритій зміні — чесна відмова', $r['state'] === 'error');
    }

    private function testFlowTestingMismatch(): void
    {
        $this->group('тестова й робоча зміни не змішуються');
        $this->fsReset(true, 90, true);
        DB::update('stores', ['dps_shift_open' => 1, 'dps_next_num' => 90, 'dps_synced_at' => now(), 'dps_testing' => 1], 'id = ?', [$this->store]);
        Settings::set('dps_testing', '0');
        $id = $this->receipt();
        $r = $this->sign($id);
        Settings::set('dps_testing', '1');
        $this->ok('відмова з поясненням про Z-звіт', $r['state'] === 'error' && str_contains((string)Fiscal::byId($id)['error'], 'Z-звітом'));
        $this->ok('у ДПС нічого не пішло', $this->fs['sent'] === 0);
    }

    private function testFlowBusyAndOwnership(): void
    {
        $this->group('одна вкладка на документ, лише свої документи');
        $this->fsReset(true, 100);
        DB::update('stores', ['dps_shift_open' => 1, 'dps_next_num' => 100, 'dps_synced_at' => now(), 'dps_testing' => 1], 'id = ?', [$this->store]);
        $id = $this->receipt();
        $a = DpsFlow::step($id, $this->user, 'TABA');
        $b = DpsFlow::step($id, $this->user, 'TABB');
        $this->ok('друга вкладка чекає, поки працює перша', $a['action'] === 'sign' && $b['action'] === 'busy');
        $c = DpsFlow::step($id, $this->user + 999999, 'TABC');
        $this->ok('чужий користувач — відмова', $c['action'] === 'done' && $c['state'] === 'error');
        $d = DpsFlow::submit($id, $this->user, 'TABA', 'doc', $a['data']);
        $this->ok('перша вкладка доводить до кінця', $d['action'] === 'done' && $d['state'] === 'done');
    }

    // ─────────────────────────────────────────────────────────────── оснастка

    private function setUp(): void
    {
        foreach (['notify_all_enabled', 'fiscal_provider', 'dps_testing'] as $k) $this->settingsWas[$k] = Settings::get($k, null);
        Settings::set('notify_all_enabled', '0');
        Settings::set('fiscal_provider', 'dps');
        Settings::set('dps_testing', '1');
        Dps::$transport = fn($p, $b, $t) => $this->fake($p, $b, $t);

        $this->owner = (int)DB::insert('owners', ['name' => 'ФОП Тест', 'tax_id' => '2644016419',
            'full_name' => 'ФОП Тестовий Тест Тестович', 'vat' => 0, 'active' => 1, 'sort' => 99, 'created_at' => now()]);
        $this->store = (int)DB::insert('stores', ['name' => 'Тест ПРРО', 'slug' => 'test-dps-' . bin2hex(random_bytes(3)),
            'city' => 'Київ', 'address' => 'вул. Тестова, 1', 'owner_id' => $this->owner, 'active' => 0, 'sort' => 999,
            'dps_fiscal_num' => '4000123456', 'dps_local_num' => 1]);
        $this->user = (int)DB::insert('users', ['email' => 'dps-test-' . bin2hex(random_bytes(4)) . '@bofu.local',
            'name' => 'Тестовий касир', 'role' => 'seller', 'active' => 1, 'created_at' => now()]);
        $head = ['number' => 'DPS-TEST-' . random_int(1000, 9999), 'token' => bin2hex(random_bytes(16)), 'user_id' => null,
                 'name' => 'Тест', 'phone' => '+380671112233', 'email' => null, 'delivery' => 'pickup',
                 'status' => 'new', 'subtotal' => 412.6, 'discount' => 0, 'total' => 412.6, 'created_at' => now()];
        $this->parent = (int)DB::insert('orders', $head);
        $this->child = (int)DB::insert('orders', ['parent_id' => $this->parent, 'store_id' => $this->store,
            'number' => $head['number'] . '/1', 'token' => bin2hex(random_bytes(16))] + $head);
    }

    private function tearDown(): void
    {
        Dps::$transport = null;
        DB::delete('fiscal_receipts', 'store_id = ?', [$this->store]);
        DB::delete('order_events', 'parent_id = ?', [$this->parent]);
        DB::delete('orders', 'parent_id = ?', [$this->parent]);
        DB::delete('orders', 'id = ?', [$this->parent]);
        DB::delete('stores', 'id = ?', [$this->store]);
        DB::delete('owners', 'id = ?', [$this->owner]);
        DB::delete('users', 'id = ?', [$this->user]);
        foreach ($this->settingsWas as $k => $v) Settings::set($k, $v ?? '');
    }

    private function ok(string $what, bool $cond): void
    {
        if ($cond) { $this->pass++; echo "  ok   $what\n"; }
        else { $this->fail++; echo "  FAIL $what\n"; }
    }

    private function group(string $name): void { echo "\n== $name ==\n"; }
}

return (new DpsTest())->run();
