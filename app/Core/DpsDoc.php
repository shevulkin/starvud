<?php
declare(strict_types=1);

/**
 * Переклад нейтрального чека на мову ПРРО ДПС (фіскальний сервер
 * fs.tax.gov.ua, протокол ЄВПЕЗ, схеми check01.xsd / zrep01.xsd).
 *
 * Чим ДПС відрізняється від Вчасно — і чому клас більший за VchasnoDoc.
 * У Вчасно чек складає і підписує їхня каса: ми віддаємо рядки й суми, а
 * локальні номери, зміну й Z-звіт вона веде сама. У ДПС посередника немає:
 * документ має прийти вже готовим — з наскрізним локальним номером ПРРО,
 * реквізитами продавця й часом операції, — і ще й підписаним КЕП касира.
 * Тому тут не лише «перекласти», а й «скласти».
 *
 * Скласти документ у мить, коли його поставили в чергу, не можна: номер і
 * час мусять бути тими, з якими його підпишуть. Тому body() повертає сам
 * нейтральний документ, а XML будує DpsFlow — безпосередньо перед підписом.
 *
 * Кодування: сервер приймає XML у windows-1251. Символи, яких у ній немає
 * (апостроф ʼ, типографські лапки, емодзі), замінюємо заздалегідь: інакше
 * libxml вписав би їх числовими посиланнями, і назва товару в чеку стала б
 * «&#700;».
 */
class DpsDoc
{
    /** Адреса фіскального сервера за замовчуванням (Налаштування → dps_fs_url) */
    public const FS_URL = 'https://fs.tax.gov.ua:8643/fs';

    /** Нейтральне завдання → [DOCTYPE, DOCSUBTYPE] */
    private const CHECK_TYPES = [
        'sell'     => [0, 0],
        'return'   => [0, 1],
        'cash_in'  => [0, 2],
        'cash_out' => [0, 4],
    ];

    /**
     * Наш вид оплати (Vchasno::PAY_TYPES) → форма оплати ДПС.
     * «Безготівка (рахунок)» тут навмисно відсутня: оплата на рахунок ФОП
     * проходить через банк, а не через касу, і чека ПРРО не потребує.
     */
    private const PAY_FORMS = [
        0 => [0, 'ГОТІВКА'],
        2 => [1, 'БАНКІВСЬКА КАРТКА'],
    ];

    /**
     * Податкова група (Vchasno::TAX_GROUPS) → літера й ставка ПДВ.
     * Лише для платників ПДВ. Літери мають збігатися з тими, що вказані
     * при реєстрації ПРРО (форма 1-ПРРО), — це типовий розклад.
     */
    private const VAT_LETTERS = [
        1 => ['А', 20.0],
        4 => ['Б', 7.0],
        5 => ['В', 0.0],
    ];

    /** Код одиниці «штука» за класифікатором (як у прикладах ДПС) */
    private const UNIT_PCS = [2009, 'шт'];

    // ───────────────────────────────────────────── контракт постачальника

    /**
     * «Запит» для черги. XML тут ще не будуємо (див. опис класу) — лише
     * перевіряємо, що завдання взагалі можна виконати, і віддаємо документ.
     */
    public static function body(array $doc, array $route): array
    {
        $task = (string)($doc['task'] ?? '');
        $known = ['sell', 'return', 'cash_in', 'cash_out', 'shift_open', 'shift_close', 'x_report'];
        if (!in_array($task, $known, true)) return [];
        // Непридатну оплату (на рахунок) не відкидаємо тут мовчки: check()
        // скаже про неї людськими словами в картці замовлення
        return ['provider' => 'dps', 'task' => $task];
    }

    /** Браузер до ДПС не ходить — підписує й віддає нам. Адреси для нього немає. */
    public static function url(array $route): string
    {
        return '';
    }

    /**
     * Наш сирий результат (його складає DpsFlow) → вигляд Fiscal.
     *
     * res: 0 — успіх; > 0 — сервер відмовив по суті (код ДПС);
     *      < 0 — відповіді не було, стан документа невідомий.
     *
     * @return array{ok:bool,error:string,res:int,receipt:array}
     */
    public static function parse(array $resp): array
    {
        if (!$resp) return ['ok' => false, 'error' => 'Фіскальний сервер не відповів', 'res' => -1, 'receipt' => []];
        $res = (int)($resp['res'] ?? -1);
        if ($res !== 0) {
            $err = trim((string)($resp['error'] ?? ''));
            return ['ok' => false, 'error' => $err !== '' ? $err : 'Фіскальний сервер відмовив (код ' . $res . ')',
                    'res' => $res, 'receipt' => []];
        }
        $info = (array)($resp['info'] ?? []);
        $num = trim((string)($info['doccode'] ?? ''));
        return [
            'ok' => true, 'error' => '', 'res' => 0,
            'receipt' => $num === '' ? [] : [
                'fiscal_number' => $num,
                'rro_number' => (string)($info['fisid'] ?? ''),
                'shift_link' => null,
                'doc_no' => isset($info['docno']) ? (int)$info['docno'] : null,
                'dt' => (string)($info['dt'] ?? ''),
                'qr' => (string)($info['qr'] ?? ''),
                'cancel_id' => '',
                'is_offline' => false,
                'is_test' => !empty($info['testing']),
            ],
        ];
    }

    // ─────────────────────────────────────────────────── реквізити продавця

    /**
     * Реквізити ПРРО й продавця для заголовка документа.
     *
     * @return array{ok:bool,missing:string[],tin:string,orgnm:string,pointnm:string,pointaddr:string,cashdesk:int,rro:string,vat:bool,testing:bool}
     */
    public static function ctx(?array $store): array
    {
        $owner = $store ? Owners::ofStore((int)$store['id']) : null;
        $out = [
            'tin' => preg_replace('/\s+/', '', (string)($owner['tax_id'] ?? '')),
            'orgnm' => trim((string)($owner['full_name'] ?? '')) ?: trim((string)($owner['name'] ?? '')),
            'pointnm' => trim((string)($store['dps_point_name'] ?? '')) ?: trim((string)($store['name'] ?? '')),
            'pointaddr' => trim((string)($store['dps_point_addr'] ?? '')) ?: trim(implode(', ', array_filter([
                (string)($store['city'] ?? ''), (string)($store['address'] ?? '')]))),
            'cashdesk' => (int)($store['dps_local_num'] ?? 0),
            'rro' => preg_replace('/\D/', '', (string)($store['dps_fiscal_num'] ?? '')),
            'vat' => !empty($owner['vat']),
            'testing' => Settings::bool('dps_testing', true),
        ];
        $missing = [];
        if (!$store) $missing[] = 'не вибрано магазин';
        if ($out['rro'] === '') $missing[] = 'у картці магазину не вказано фіскальний номер ПРРО';
        if ($out['cashdesk'] <= 0) $missing[] = 'у картці магазину не вказано локальний номер ПРРО';
        if (!preg_match('/^([0-9]{5,10}|[А-ЯЄІ]{2}[0-9]{6})$/u', $out['tin'])) {
            $missing[] = 'у власника точки не вказано РНОКПП / ЄДРПОУ';
        }
        if ($out['orgnm'] === '') $missing[] = 'у власника точки не вказано назву';
        if ($out['pointnm'] === '') $missing[] = 'не вказано назву господарської одиниці';
        return ['ok' => !$missing, 'missing' => $missing] + $out;
    }

    // ─────────────────────────────────────────────────────────── документи

    /**
     * Чек: продаж, повернення, службове внесення чи видача.
     *
     * $doc — нейтральний документ Fiscal: rows (name, cnt, price, disc,
     * taxgrp, code, code1, code2), pays (type, sum, change), sum, round.
     * $meta: num (локальний номер), dt (unix-час), uid, ret (фіскальний номер
     * чека продажу для повернення).
     *
     * @throws RuntimeException коли документ неможливо скласти (невідома оплата, група)
     */
    public static function check(array $doc, array $ctx, array $meta): string
    {
        $task = (string)($doc['task'] ?? 'sell');
        if (!isset(self::CHECK_TYPES[$task])) throw new RuntimeException('Невідомий тип чека: ' . $task);
        [$type, $sub] = self::CHECK_TYPES[$task];

        $head = self::head($ctx, $meta, $type, $sub, (string)($doc['cashier'] ?? ''),
            $task === 'return' ? (string)($meta['ret'] ?? '') : '');

        // Службове внесення / видача: лише сума, без товарів і оплат
        if ($task === 'cash_in' || $task === 'cash_out') {
            $sum = self::money((float)($doc['cash']['sum'] ?? 0));
            return self::xml('CHECK', 'check01.xsd', [
                'CHECKHEAD' => $head,
                'CHECKTOTAL' => ['SUM' => $sum],
            ]);
        }

        $rows = (array)($doc['rows'] ?? []);
        if (!$rows) throw new RuntimeException('У чеку немає рядків');

        $body = []; $taxes = []; $discTotal = 0.0; $sum = 0.0;
        foreach (array_values($rows) as $i => $r) {
            $cnt = (float)($r['cnt'] ?? 1);
            $price = round((float)($r['price'] ?? 0), 2);
            $cost = round($cnt * $price, 2);
            $disc = round(max(0.0, (float)($r['disc'] ?? 0)), 2);
            $row = [];
            $code = self::clean((string)($r['code'] ?? ''), 64);
            if ($code !== '') $row['CODE'] = $code;
            $barcode = preg_replace('/\D/', '', (string)($r['code1'] ?? ''));
            if ($barcode !== '') $row['BARCODE'] = mb_substr($barcode, 0, 64);
            $uktzed = preg_replace('/\D/', '', (string)($r['code2'] ?? ''));
            if (preg_match('/^([0-9]{10}|[0-9]{8}|[0-9]{6}|[0-9]{4})$/', $uktzed)) $row['UKTZED'] = $uktzed;
            $row['NAME'] = self::clean((string)($r['name'] ?? 'Товар'), 1024) ?: 'Товар';
            $row['UNITCD'] = (string)self::UNIT_PCS[0];
            $row['UNITNM'] = self::UNIT_PCS[1];
            $row['AMOUNT'] = self::qty($cnt);
            $row['PRICE'] = self::money($price);
            if ($ctx['vat']) {
                $letter = self::vatLetter((int)($r['taxgrp'] ?? 2));
                $row['LETTERS'] = $letter[0];
                $key = $letter[0];
                $taxes[$key] ??= ['letter' => $letter[0], 'prc' => $letter[1], 'turnover' => 0.0, 'source' => 0.0];
                $taxes[$key]['turnover'] += $cost;
                $taxes[$key]['source'] += $cost - $disc;
            }
            $row['COST'] = self::money($cost);
            if ($disc > 0) {
                // Сумова знижка на рядок: вартість лишається «до знижки»,
                // а знижка — окремим полем (так її бачить і покупець, і ДПС)
                $row['DISCOUNTTYPE'] = '0';
                $row['DISCOUNTSUM'] = self::money($disc);
            }
            $body[] = ['@ROWNUM' => (string)($i + 1)] + $row;
            $discTotal += $disc;
            $sum += $cost - $disc;
        }
        $sum = round($sum, 2);
        $discTotal = round($discTotal, 2);

        // Оплата. Готівка округлюється до 10 копійок окремим полем:
        // RNDSUM = сума без заокруглення − сума до сплати (приклад ДПС:
        // 12.57 → 12.60, RNDSUM = −0.03).
        $round = round((float)($doc['round'] ?? 0), 2);
        $paid = round($sum + $round, 2);
        $pays = [];
        foreach (array_values((array)($doc['pays'] ?? [])) as $i => $p) {
            $t = (int)($p['type'] ?? 0);
            if (!isset(self::PAY_FORMS[$t])) {
                throw new RuntimeException('Оплату на рахунок не фіскалізують чеком ПРРО — оберіть готівку чи картку');
            }
            [$cd, $nm] = self::PAY_FORMS[$t];
            $row = ['@ROWNUM' => (string)($i + 1), 'PAYFORMCD' => (string)$cd, 'PAYFORMNM' => $nm,
                    'SUM' => self::money((float)($p['sum'] ?? $paid))];
            $change = round((float)($p['change'] ?? 0), 2);
            if ($t === 0 && $change > 0) {
                $row['PROVIDED'] = self::money((float)($p['sum'] ?? $paid) + $change);
                $row['REMAINS'] = self::money($change);
            }
            $pays[] = $row;
        }
        if (!$pays) $pays[] = ['@ROWNUM' => '1', 'PAYFORMCD' => '0', 'PAYFORMNM' => 'ГОТІВКА', 'SUM' => self::money($paid)];

        $total = ['SUM' => self::money($paid)];
        if (abs($round) >= 0.005) {
            $total['RNDSUM'] = self::money(-$round);
            $total['NORNDSUM'] = self::money($sum);
        }
        if ($discTotal > 0) $total['DISCOUNTSUM'] = self::money($discTotal);

        $parts = ['CHECKHEAD' => $head, 'CHECKTOTAL' => $total, 'CHECKPAY' => ['ROW' => $pays]];
        if ($taxes) {
            $t = []; $n = 0;
            foreach ($taxes as $x) {
                $source = round($x['source'], 2);
                $t[] = ['@ROWNUM' => (string)++$n, 'TYPE' => '0', 'NAME' => 'ПДВ', 'LETTER' => $x['letter'],
                        'PRC' => self::money($x['prc']), 'SIGN' => 'false',
                        'TURNOVER' => self::money($x['turnover']), 'SOURCESUM' => self::money($source),
                        'SUM' => self::money(round($source * $x['prc'] / (100 + $x['prc']), 2))];
            }
            $parts['CHECKTAX'] = ['ROW' => $t];
        }
        $parts['CHECKBODY'] = ['ROW' => $body];
        return self::xml('CHECK', 'check01.xsd', $parts);
    }

    /** Відкриття (100) чи закриття (101) зміни — службове повідомлення без тіла */
    public static function shift(bool $open, array $ctx, array $meta, string $cashier): string
    {
        return self::xml('CHECK', 'check01.xsd', [
            'CHECKHEAD' => self::head($ctx, $meta, $open ? 100 : 101, null, $cashier, ''),
        ]);
    }

    /**
     * Z-звіт із підсумків зміни, які віддав сам сервер (LastShiftTotals).
     *
     * Будувати з підсумків сервера, а не з нашої бази, — навмисно: Z-звіт
     * звіряють із тим, що зареєстровано в ДПС, і будь-яка розбіжність (чек,
     * пробитий у кабінеті вручну) зробила б звіт неприйнятним.
     */
    public static function zrep(array $totals, array $ctx, array $meta, string $cashier): string
    {
        $head = self::head($ctx, $meta, null, null, $cashier, '');
        unset($head['DOCTYPE'], $head['DOCSUBTYPE']);
        $parts = ['ZREPHEAD' => $head];
        foreach (['Real' => 'ZREPREALIZ', 'Ret' => 'ZREPRETURN'] as $key => $tag) {
            $t = (array)($totals[$key] ?? []);
            if (!$t || (float)($t['Sum'] ?? 0) == 0.0 && (int)($t['OrdersCount'] ?? 0) === 0) continue;
            $sec = ['SUM' => self::money((float)($t['Sum'] ?? 0))];
            if (isset($t['RndSum']) && (float)$t['RndSum'] != 0.0) {
                $sec['RNDSUM'] = self::money((float)$t['RndSum']);
                $sec['NORNDSUM'] = self::money((float)($t['NoRndSum'] ?? 0));
            }
            $sec['ORDERSCNT'] = (string)(int)($t['OrdersCount'] ?? 0);
            $pf = [];
            foreach (array_values((array)($t['PayForm'] ?? [])) as $i => $p) {
                $pf[] = ['@ROWNUM' => (string)($i + 1), 'PAYFORMCD' => (string)(int)($p['PayFormCode'] ?? 0),
                         'PAYFORMNM' => self::clean((string)($p['PayFormName'] ?? ''), 128),
                         'SUM' => self::money((float)($p['Sum'] ?? 0))];
            }
            if ($pf) $sec['PAYFORMS'] = ['ROW' => $pf];
            $tx = [];
            foreach (array_values((array)($t['Tax'] ?? [])) as $i => $x) {
                $row = ['@ROWNUM' => (string)($i + 1), 'TYPE' => (string)(int)($x['Type'] ?? 0),
                        'NAME' => self::clean((string)($x['Name'] ?? ''), 64)];
                if (($x['Letter'] ?? '') !== '') $row['LETTER'] = self::clean((string)$x['Letter'], 1);
                $row['PRC'] = self::money((float)($x['Prc'] ?? 0));
                $row['SIGN'] = !empty($x['Sign']) ? 'true' : 'false';
                $row['TURNOVER'] = self::money((float)($x['Turnover'] ?? 0));
                if (isset($x['SourceSum'])) $row['SOURCESUM'] = self::money((float)$x['SourceSum']);
                $row['SUM'] = self::money((float)($x['Sum'] ?? 0));
                $tx[] = $row;
            }
            if ($tx) $sec['TAXES'] = ['ROW' => $tx];
            $parts[$tag] = $sec;
        }
        $parts['ZREPBODY'] = [
            'SERVICEINPUT' => self::money((float)($totals['ServiceInput'] ?? 0)),
            'SERVICEOUTPUT' => self::money((float)($totals['ServiceOutput'] ?? 0)),
        ];
        return self::xml('ZREP', 'zrep01.xsd', $parts);
    }

    // ────────────────────────────────────────────────────────────── команди

    /** Команда фіскальному серверу (JSON; частина з них підписується КЕП) */
    public static function command(string $name, array $params = []): string
    {
        return (string)json_encode(['Command' => $name] + $params + ['UID' => self::uid()],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // ───────────────────────────────────────────────────────────── квитанція

    /**
     * Квитанція сервера (TICKET, windows-1251) → масив.
     *
     * @return array{ok:bool,code:int,text:string,taxnum:string,num:string,uid:string,date:string,time:string}
     */
    public static function ticket(string $xml): array
    {
        $out = ['ok' => false, 'code' => -1, 'text' => '', 'taxnum' => '', 'num' => '', 'uid' => '', 'date' => '', 'time' => ''];
        $prev = libxml_use_internal_errors(true);
        $x = simplexml_load_string($xml);
        libxml_use_internal_errors($prev);
        if (!$x) {
            $out['text'] = 'Квитанцію не вдалося прочитати';
            return $out;
        }
        $out['code'] = (int)($x->ERRORCODE ?? 0);
        $out['text'] = trim((string)($x->ERRORTEXT ?? ''));
        $out['taxnum'] = trim((string)($x->ORDERTAXNUM ?? ''));
        $out['num'] = trim((string)($x->ORDERNUM ?? ''));
        $out['uid'] = trim((string)($x->UID ?? ''));
        $out['date'] = trim((string)($x->ORDERDATE ?? ''));
        $out['time'] = trim((string)($x->ORDERTIME ?? ''));
        $out['ok'] = $out['code'] === 0;
        return $out;
    }

    /**
     * Текстова відповідь сервера з помилкою: «Код помилки: 9 DocumentValidationError\r\n…»
     *
     * @return array{code:int,text:string}
     */
    public static function errorText(string $body): array
    {
        $body = trim(self::toUtf8($body));
        if (preg_match('/Код помилки:\s*(\d+)\s*(\S*)\s*(.*)$/su', $body, $m)) {
            $text = trim($m[3]) !== '' ? trim($m[3]) : trim($m[2]);
            return ['code' => (int)$m[1], 'text' => mb_substr(preg_replace('/\s+/u', ' ', $text), 0, 400)];
        }
        return ['code' => 0, 'text' => mb_substr(preg_replace('/\s+/u', ' ', $body), 0, 400)];
    }

    /**
     * Посилання на чек у сервісі «Пошук фіскального чека» — те саме, що в
     * QR-коді на чеку. Покупець перевіряє чек сам, без нас.
     */
    public static function checkUrl(string $fiscalNum, string $rro, int $ts, float $sum): string
    {
        if ($fiscalNum === '' || $rro === '') return '';
        return 'https://cabinet.tax.gov.ua/cashregs/check?' . http_build_query([
            'id' => $fiscalNum, 'fn' => $rro,
            'date' => date('Ymd', $ts), 'time' => date('Hi', $ts),
            'sm' => number_format($sum, 2, '.', ''),
        ]);
    }

    // ─────────────────────────────────────────────────────────────── дрібниці

    /** GUID для UID документа й команди */
    public static function uid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return strtoupper(vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4)));
    }

    public static function toUtf8(string $s): string
    {
        if ($s === '' || mb_check_encoding($s, 'UTF-8')) return $s;
        return (string)mb_convert_encoding($s, 'UTF-8', 'Windows-1251');
    }

    private static function head(array $ctx, array $meta, ?int $type, ?int $sub, string $cashier, string $ret): array
    {
        $ts = (int)($meta['dt'] ?? time());
        $h = [];
        if ($type !== null) $h['DOCTYPE'] = (string)$type;
        if ($sub !== null) $h['DOCSUBTYPE'] = (string)$sub;
        $h['UID'] = (string)($meta['uid'] ?? self::uid());
        $h['TIN'] = $ctx['tin'];
        $h['ORGNM'] = self::clean($ctx['orgnm'], 256);
        $h['POINTNM'] = self::clean($ctx['pointnm'], 256);
        if ($ctx['pointaddr'] !== '') $h['POINTADDR'] = self::clean($ctx['pointaddr'], 256);
        $h['ORDERDATE'] = date('dmY', $ts);
        $h['ORDERTIME'] = date('His', $ts);
        $h['ORDERNUM'] = (string)(int)$meta['num'];
        $h['CASHDESKNUM'] = (string)(int)$ctx['cashdesk'];
        $h['CASHREGISTERNUM'] = $ctx['rro'];
        if ($ret !== '') $h['ORDERRETNUM'] = self::clean($ret, 128);
        $cashier = self::clean($cashier, 128);
        if ($cashier !== '') $h['CASHIER'] = $cashier;
        $h['VER'] = '1';
        // TESTING заборонений лише для початку/кінця офлайн-сесії; усе інше
        // в тестовій зміні мусить бути тестовим до одного документа
        if (!empty($ctx['testing'])) $h['TESTING'] = 'true';
        return $h;
    }

    /**
     * Дерево → XML у windows-1251. Ключ '@ROWNUM' — атрибут, масив під 'ROW' —
     * повторювані рядки. Порядок ключів = порядок елементів (у схемі sequence).
     */
    private static function xml(string $root, string $xsd, array $tree): string
    {
        $d = new DOMDocument('1.0', 'windows-1251');
        $r = $d->createElement($root);
        $r->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $r->setAttribute('xsi:noNamespaceSchemaLocation', $xsd);
        $d->appendChild($r);
        self::fill($d, $r, $tree);
        return (string)$d->saveXML();
    }

    private static function fill(DOMDocument $d, DOMElement $parent, array $tree): void
    {
        foreach ($tree as $k => $v) {
            if (is_string($k) && $k[0] === '@') { $parent->setAttribute(substr($k, 1), (string)$v); continue; }
            if (is_array($v) && array_is_list($v)) {
                foreach ($v as $item) {
                    $el = $d->createElement((string)$k);
                    $parent->appendChild($el);
                    self::fill($d, $el, (array)$item);
                }
                continue;
            }
            $el = $d->createElement((string)$k);
            $parent->appendChild($el);
            if (is_array($v)) self::fill($d, $el, $v);
            else $el->appendChild($d->createTextNode((string)$v));
        }
    }

    /** Текст, придатний для windows-1251 і схеми: без керувальних символів і чужих знаків */
    public static function clean(string $s, int $max): string
    {
        $s = strtr($s, ['ʼ' => "'", '’' => "'", '‘' => "'", '`' => "'", '“' => '"', '”' => '"', '„' => '"',
                        "\u{00A0}" => ' ', '…' => '...']);
        $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s) ?? '';
        // лишаємо лише те, що є в windows-1251
        $cp = @iconv('UTF-8', 'Windows-1251//IGNORE', $s);
        $s = $cp === false ? '' : (string)iconv('Windows-1251', 'UTF-8', $cp);
        return mb_substr(trim(preg_replace('/\s+/u', ' ', $s) ?? ''), 0, $max);
    }

    private static function money(float $v): string
    {
        return number_format(round($v, 2), 2, '.', '');
    }

    private static function qty(float $v): string
    {
        $s = number_format(round($v, 3), 3, '.', '');
        return rtrim(rtrim($s, '0'), '.') ?: '0';
    }

    /** @return array{0:string,1:float} */
    private static function vatLetter(int $grp): array
    {
        if (isset(self::VAT_LETTERS[$grp])) return self::VAT_LETTERS[$grp];
        throw new RuntimeException('Податкова група «' . (Vchasno::TAX_GROUPS[$grp] ?? $grp)
            . '» для ПРРО ДПС не підтримується — оберіть ПДВ 20%, 7% або 0%');
    }
}
