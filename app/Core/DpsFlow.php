<?php
declare(strict_types=1);

/**
 * ПРРО ДПС: як документ доходить від черги до фіскального номера.
 *
 * Модель та сама, що з Device Manager у Вчасно: ключ лежить у продавця
 * (флешка чи телефон), а чек підписує його браузер. Різниця в тому, хто веде
 * касу. Device Manager сам тримає зміну й нумерацію; у ДПС цього робити нікому,
 * тож робить сервер — тут, покроково:
 *
 *   state        — звірити з ДПС стан ПРРО (чи відкрита зміна, наступний
 *                  локальний номер). Підписана команда;
 *   shift_open   — відкрити зміну, якщо закрита (документ 100);
 *   doc          — сам чек: продаж, повернення, внесення, видача;
 *   totals       — підсумки зміни від сервера (для X- і Z-звіту);
 *   zrep         — Z-звіт із тих підсумків;
 *   shift_close  — закриття зміни (документ 101);
 *   lookup       — після обриву звʼязку: чи дійшов документ до ДПС.
 *
 * Браузер не знає нічого з цього: він просить «що підписати далі», підписує
 * й віддає підписане. Уся логіка — тут, у PHP, і перевіряється тестами без
 * жодного ключа (див. tests/dps.php).
 *
 * НАЙТОНШЕ МІСЦЕ — обірваний звʼязок після відправки. Документ міг бути
 * зареєстрований, а відповідь загубилась. Повторити його «як є» не можна
 * (локальний номер уже зайнятий), скласти новий — теж (вийде другий чек).
 * Тому спершу питаємо ДПС за локальним номером (DocumentInfoByLocalNum) і лише
 * потім вирішуємо: забрати фіскальний номер чи відправити ще раз.
 */
class DpsFlow
{
    /** Як довго довіряємо збереженому стану каси, перш ніж перепитати ДПС */
    private const SYNC_TTL = 600;

    /** Скільки секунд вкладка «тримає» документ без дій, поки його не забере інша */
    private const CLAIM_TTL = 90;

    /** Захист від циклу «сервер відмовив → звірили → знову відмовив» */
    private const MAX_LOOPS = 6;

    // ─────────────────────────────────────────────────────────────── черга

    /**
     * Документи, які може підписати браузер цієї людини.
     *
     * Свої — і «нічиї» службові (Z-звіт, поставлений cron'ом, у якого автора
     * немає). Плюс ті, що завмерли на півдорозі: вкладку закрили, телефон
     * заснув, — їх підхопить будь-яка жива вкладка цієї ж людини.
     */
    public static function jobsFor(int $userId, ?int $parentId = null): array
    {
        $edge = date('Y-m-d H:i:s', time() - self::CLAIM_TTL);
        $where = "provider = 'dps' AND route = 'device'
                  AND (created_by_user_id = ? OR (created_by_user_id IS NULL AND type = 'service'))
                  AND (status = 'queued' OR (status = 'pending' AND (updated_at IS NULL OR updated_at < ?)))";
        $args = [$userId, $edge];
        if ($parentId) { $where .= ' AND parent_id = ?'; $args[] = $parentId; }
        return DB::all("SELECT * FROM fiscal_receipts WHERE $where ORDER BY id LIMIT 20", $args);
    }

    /** Вкладка з ключем вийшла на звʼязок */
    public static function heartbeat(int $userId): void
    {
        DB::update('users', ['dps_signer_seen_at' => now()], 'id = ?', [$userId]);
    }

    /** Чи є зараз у людини відкрита вкладка з ключем (для картки замовлення) */
    public static function signerAlive(int $userId): bool
    {
        $seen = (string)(DB::val('SELECT dps_signer_seen_at FROM users WHERE id = ?', [$userId]) ?? '');
        return $seen !== '' && strtotime($seen) > time() - 45;
    }

    public static function label(array $r): string
    {
        $names = ['sell' => 'Чек продажу', 'return' => 'Чек повернення', 'cash_in' => 'Службове внесення',
                  'cash_out' => 'Службова видача', 'shift_open' => 'Відкриття зміни',
                  'shift_close' => 'Z-звіт і закриття зміни', 'x_report' => 'X-звіт'];
        $n = $names[(string)$r['task']] ?? 'Документ';
        return $n . ((float)$r['sum'] > 0 ? ' на ' . price_fmt((float)$r['sum']) : '');
    }

    // ──────────────────────────────────────────────────────────── кроки

    /**
     * Узяти документ у роботу й сказати, що підписати першим.
     *
     * @return array action: sign | done | wait | busy
     */
    public static function step(int $id, int $userId, string $tab): array
    {
        $r = self::own($id, $userId);
        if (!$r) return ['action' => 'done', 'state' => 'error', 'error' => 'Це завдання не ваше'];
        if ($r['status'] === 'done') return self::doneView($r);
        if ($r['status'] === 'error') return self::doneView($r);

        $st = self::st($r);
        $fresh = $r['updated_at'] && strtotime((string)$r['updated_at']) > time() - self::CLAIM_TTL;
        if ($r['status'] === 'pending' && ($st['tab'] ?? '') !== $tab && $fresh) {
            return ['action' => 'busy', 'error' => 'Цей документ зараз підписує інша вкладка'];
        }
        $st['tab'] = $tab;
        DB::update('fiscal_receipts', ['status' => 'pending', 'step' => json_encode($st, JSON_UNESCAPED_UNICODE),
                                       'updated_at' => now()], 'id = ?', [$id]);
        return self::next(Fiscal::byId($id));
    }

    /**
     * Прийняти підписане, донести до ДПС і сказати, що далі.
     *
     * @return array action: sign | done | wait | busy
     */
    public static function submit(int $id, int $userId, string $tab, string $purpose, string $signedB64): array
    {
        $r = self::own($id, $userId);
        if (!$r) return ['action' => 'done', 'state' => 'error', 'error' => 'Це завдання не ваше'];
        if (in_array($r['status'], ['done', 'error'], true)) return self::doneView($r);
        $st = self::st($r);
        if (($st['tab'] ?? '') !== $tab) return ['action' => 'busy', 'error' => 'Цей документ зараз підписує інша вкладка'];
        // Підписали не те, що зараз чекаємо (дві вкладки, повтор) — видаємо актуальне
        if (($st['purpose'] ?? '') !== $purpose || empty($st['data'])) return self::next($r);

        $bytes = base64_decode($signedB64, true);
        if ($bytes === false || strlen($bytes) < 64 || strlen($bytes) > 200000) {
            return ['action' => 'wait', 'error' => 'Підпис не вийшов — спробуйте ще раз'];
        }
        $store = self::store($r);
        $ctx = DpsDoc::ctx($store);
        $st['loops'] = (int)($st['loops'] ?? 0) + 1;
        if ($st['loops'] > self::MAX_LOOPS) {
            return self::fail($r, 'Фіскальний сервер раз за разом відхиляє документ. Перевірте стан каси в кабінеті ДПС.', 9);
        }

        $isCmd = in_array($purpose, ['state', 'lookup', 'totals'], true);
        $resp = Dps::post($isCmd ? 'cmd' : 'doc', $bytes, true);
        if ($resp['status'] === 0) {
            if ($isCmd) return self::wait($r, $st, 'Немає звʼязку з ДПС: ' . $resp['error']);
            // Документ міг дійти — наступним кроком спитаємо про нього
            $st['uncertain'] = true;
            $st['sent'] = $purpose;
            self::save($r, $st);
            DB::update('fiscal_receipts', ['attempts' => (int)$r['attempts'] + 1], 'id = ?', [(int)$r['id']]);
            return self::wait($r, $st, 'Звʼязок із ДПС обірвався. Перевіримо, чи дійшов документ, щойно він зʼявиться.');
        }

        switch ($purpose) {
            case 'state':
                if ($resp['status'] === 204) {
                    return self::fail($r, 'ПРРО №' . $ctx['rro'] . ' не знайдено в ДПС або цей ключ не має до нього доступу. '
                        . 'Перевірте фіскальний номер у картці магазину і що касира зареєстровано (форма 5-ПРРО).', 1);
                }
                $j = self::json($resp);
                if ($j === null) return self::refused($r, $st, $resp, $purpose);
                self::syncStore($store, $j);
                unset($st['purpose'], $st['data']);
                self::save($r, $st);
                return self::next(Fiscal::byId((int)$r['id']));

            case 'lookup':
                if ($resp['status'] === 204) {
                    // Не дійшов — складемо заново з тим самим номером
                    unset($st['uncertain'], $st['sent'], $st['purpose'], $st['data']);
                    self::save($r, $st);
                    return self::next(Fiscal::byId((int)$r['id']));
                }
                $j = self::json($resp);
                if ($j === null) return self::refused($r, $st, $resp, $purpose);
                $num = trim((string)($j['NumFiscal'] ?? ''));
                return self::registered($r, $st + ['purpose' => $st['sent'] ?? 'doc'], $store, $ctx, $num, (string)($st['sent'] ?? 'doc'));

            case 'totals':
                $j = $resp['status'] === 204 ? [] : self::json($resp);
                if ($j === null) return self::refused($r, $st, $resp, $purpose);
                $totals = (array)($j['Totals'] ?? []);
                if ((string)$r['task'] === 'x_report') {
                    return self::finish($r, ['totals' => $totals, 'shift_state' => (int)($j['ShiftState'] ?? 0)]);
                }
                $st['totals'] = $totals ?: ['empty' => true];
                unset($st['purpose'], $st['data']);
                self::save($r, $st);
                return self::next(Fiscal::byId((int)$r['id']));

            default: // shift_open | doc | zrep | shift_close
                if ($resp['status'] === 200) {
                    $t = DpsDoc::ticket(Dps::content($resp['body']));
                    if ($t['ok']) return self::registered($r, $st, $store, $ctx, $t['taxnum'], $purpose);
                    return self::code($r, $st, $store, $t['code'], $t['text'], $purpose);
                }
                $e = DpsDoc::errorText($resp['body']);
                return self::code($r, $st, $store, $e['code'], $e['text'] ?: ('HTTP ' . $resp['status']), $purpose);
        }
    }

    /** Що підписати далі — з поточного стану документа й каси */
    public static function next(array $r): array
    {
        if (in_array($r['status'], ['done', 'error'], true)) return self::doneView($r);
        $store = self::store($r);
        $ctx = DpsDoc::ctx($store);
        if (!$ctx['ok']) return self::fail($r, 'Каса не налаштована: ' . implode('; ', $ctx['missing']) . '.', 1);
        $st = self::st($r);
        $rro = $ctx['rro'];

        if (!empty($st['uncertain'])) {
            return self::sign($r, $st, 'lookup', DpsDoc::command('DocumentInfoByLocalNum',
                ['NumFiscal' => $rro, 'NumLocal' => (string)(int)$st['num']]), 'Звіряємо з ДПС попередній документ');
        }
        if (self::needSync($store)) {
            return self::sign($r, $st, 'state', DpsDoc::command('TransactionsRegistrarState', ['NumFiscal' => $rro]),
                'Перевіряємо стан каси в ДПС');
        }

        $open = (int)$store['dps_shift_open'] === 1;
        $task = (string)$r['task'];
        switch ($task) {
            case 'sell': case 'return': case 'cash_in': case 'cash_out':
                if (!$open) return self::build($r, $st, $store, $ctx, 'shift_open');
                if ($store['dps_testing'] !== null && (bool)$store['dps_testing'] !== $ctx['testing']) {
                    return self::fail($r, 'Зараз відкрита ' . ($store['dps_testing'] ? 'ТЕСТОВА' : 'фіскальна')
                        . ' зміна, а в налаштуваннях — ' . ($ctx['testing'] ? 'тестовий' : 'робочий')
                        . ' режим. В одній зміні їх змішувати не можна: закрийте зміну Z-звітом.', 1);
                }
                return self::build($r, $st, $store, $ctx, 'doc');
            case 'shift_open':
                if ($open) return self::finish($r, ['note' => 'Зміну вже відкрито']);
                return self::build($r, $st, $store, $ctx, 'shift_open');
            case 'x_report':
                if (!$open) return self::fail($r, 'Зміну не відкрито — звітувати нема про що.', 5);
                return self::sign($r, $st, 'totals', DpsDoc::command('LastShiftTotals', ['NumFiscal' => $rro]),
                    'Питаємо підсумки зміни');
            case 'shift_close':
                if (!$open) return self::finish($r, ['note' => 'Зміну вже закрито']);
                if (($st['stage'] ?? '') === 'zrep_ok') return self::build($r, $st, $store, $ctx, 'shift_close');
                if (!empty($st['totals'])) return self::build($r, $st, $store, $ctx, 'zrep');
                return self::sign($r, $st, 'totals', DpsDoc::command('LastShiftTotals', ['NumFiscal' => $rro]),
                    'Питаємо підсумки зміни для Z-звіту');
        }
        return self::fail($r, 'Невідоме завдання: ' . $task, 9);
    }

    // ────────────────────────────────────────────────────────── внутрішнє

    /** Скласти документ із наступним локальним номером і віддати на підпис */
    private static function build(array $r, array $st, array $store, array $ctx, string $purpose): array
    {
        $num = (int)($store['dps_next_num'] ?? 0);
        if ($num <= 0) {
            DB::update('stores', ['dps_synced_at' => null], 'id = ?', [(int)$store['id']]);
            return self::next(Fiscal::byId((int)$r['id']));
        }
        $meta = ['num' => $num, 'dt' => time(), 'uid' => DpsDoc::uid()];
        $doc = json_decode((string)$r['doc'], true) ?: [];
        $cashier = (string)($doc['cashier'] ?? '');
        try {
            $xml = match ($purpose) {
                'shift_open' => DpsDoc::shift(true, $ctx, $meta, $cashier),
                'shift_close' => DpsDoc::shift(false, $ctx, $meta, $cashier),
                'zrep' => DpsDoc::zrep(isset($st['totals']['empty']) ? [] : (array)$st['totals'], $ctx, $meta, $cashier),
                default => DpsDoc::check($doc, $ctx, $meta + ['ret' => self::retNumber($r)]),
            };
        } catch (RuntimeException $e) {
            return self::fail($r, $e->getMessage(), 9);
        }
        $st = array_merge($st, $meta);
        $labels = ['shift_open' => 'Відкриваємо зміну', 'shift_close' => 'Закриваємо зміну',
                   'zrep' => 'Z-звіт', 'doc' => self::label($r)];
        return self::sign($r, $st, $purpose, $xml, $labels[$purpose] ?? 'Документ');
    }

    /** Фіскальний номер чека продажу — для чека повернення */
    private static function retNumber(array $r): string
    {
        if ((string)$r['task'] !== 'return' || !$r['of_receipt_id']) return '';
        return (string)(DB::val('SELECT fiscal_number FROM fiscal_receipts WHERE id = ?', [(int)$r['of_receipt_id']]) ?? '');
    }

    private static function sign(array $r, array $st, string $purpose, string $data, string $label): array
    {
        $st['purpose'] = $purpose;
        $st['data'] = base64_encode($data);
        self::save($r, $st);
        return ['action' => 'sign', 'id' => (int)$r['id'], 'purpose' => $purpose, 'data' => $st['data'], 'label' => $label];
    }

    /** Документ зареєстровано: зсуваємо нумерацію й ведемо далі */
    private static function registered(array $r, array $st, array $store, array $ctx, string $fiscal, string $purpose): array
    {
        $sid = (int)$store['id'];
        $upd = ['dps_next_num' => (int)$st['num'] + 1];
        unset($st['uncertain'], $st['sent'], $st['purpose'], $st['data']);
        $st['loops'] = 0;

        switch ($purpose) {
            case 'shift_open':
                DB::update('stores', $upd + ['dps_shift_open' => 1, 'dps_shift_at' => now(),
                                             'dps_testing' => $ctx['testing'] ? 1 : 0], 'id = ?', [$sid]);
                if ((string)$r['task'] === 'shift_open') return self::finish($r, ['shift' => $fiscal]);
                self::save($r, $st);
                return self::next(Fiscal::byId((int)$r['id']));
            case 'zrep':
                DB::update('stores', $upd, 'id = ?', [$sid]);
                $st['stage'] = 'zrep_ok';
                $st['zrep'] = $fiscal;
                self::save($r, $st);
                return self::next(Fiscal::byId((int)$r['id']));
            case 'shift_close':
                DB::update('stores', $upd + ['dps_shift_open' => 0, 'dps_testing' => null], 'id = ?', [$sid]);
                return self::finish($r, ['zrep' => (string)($st['zrep'] ?? ''), 'totals' => $st['totals'] ?? []]);
            default: // doc
                DB::update('stores', $upd, 'id = ?', [$sid]);
                self::save($r, $st);
                if ((string)$r['type'] === 'service') return self::finish($r, ['fiscal' => $fiscal]);
                $ts = (int)($st['dt'] ?? time());
                $res = Fiscal::applyRaw((int)$r['id'], ['res' => 0, 'info' => [
                    'doccode' => $fiscal, 'fisid' => $ctx['rro'], 'docno' => (int)$st['num'],
                    'dt' => date('YmdHis', $ts), 'testing' => $ctx['testing'],
                    'qr' => DpsDoc::checkUrl($fiscal, $ctx['rro'], $ts, (float)$r['sum']),
                ]]);
                return self::doneView($res['receipt'] ?? Fiscal::byId((int)$r['id']));
        }
    }

    /** Відмова сервера з кодом — частину виправляємо самі, решту показуємо людині */
    private static function code(array $r, array $st, array $store, int $code, string $text, string $purpose): array
    {
        $sid = (int)$store['id'];
        unset($st['purpose'], $st['data']);
        switch ($code) {
            case 4: // зміну вже відкрито
                DB::update('stores', ['dps_shift_open' => 1, 'dps_synced_at' => null], 'id = ?', [$sid]);
                break;
            case 5: // зміну не відкрито
                DB::update('stores', ['dps_shift_open' => 0, 'dps_synced_at' => null], 'id = ?', [$sid]);
                break;
            case 6: // перед закриттям зміни потрібен Z-звіт
                unset($st['stage'], $st['totals']);
                DB::update('stores', ['dps_synced_at' => null], 'id = ?', [$sid]);
                break;
            case 7: // не той локальний номер — хтось пробив документ з іншого пристрою
                DB::update('stores', ['dps_synced_at' => null], 'id = ?', [$sid]);
                break;
            case 8: // Z-звіт уже зареєстровано
                if ($purpose === 'zrep') { $st['stage'] = 'zrep_ok'; break; }
                return self::fail($r, $text, $code);
            default:
                return self::fail($r, $text, $code);
        }
        self::save($r, $st);
        return self::next(Fiscal::byId((int)$r['id']));
    }

    private static function refused(array $r, array $st, array $resp, string $purpose): array
    {
        $e = DpsDoc::errorText($resp['body']);
        return self::fail($r, $e['text'] ?: ('Фіскальний сервер відповів HTTP ' . $resp['status']), $e['code'] ?: 9);
    }

    /** Стан ПРРО з відповіді TransactionsRegistrarState — у картку магазину */
    private static function syncStore(array $store, array $j): void
    {
        $open = (int)($j['ShiftState'] ?? 0) === 1;
        DB::update('stores', [
            'dps_shift_open' => $open ? 1 : 0,
            'dps_next_num' => max(1, (int)($j['NextLocalNum'] ?? 1)),
            'dps_testing' => $open ? (!empty($j['Testing']) ? 1 : 0) : null,
            'dps_synced_at' => now(),
            'dps_shift_at' => $open ? ($store['dps_shift_at'] ?: now()) : null,
        ], 'id = ?', [(int)$store['id']]);
    }

    private static function needSync(array $store): bool
    {
        $at = trim((string)($store['dps_synced_at'] ?? ''));
        return $at === '' || strtotime($at) < time() - self::SYNC_TTL || (int)($store['dps_next_num'] ?? 0) <= 0;
    }

    private static function finish(array $r, array $info): array
    {
        DB::update('fiscal_receipts', ['step' => null], 'id = ?', [(int)$r['id']]);
        $res = Fiscal::applyRaw((int)$r['id'], ['res' => 0, 'info' => $info]);
        return self::doneView($res['receipt'] ?? Fiscal::byId((int)$r['id']));
    }

    private static function fail(array $r, string $text, int $code): array
    {
        $st = self::st($r);
        // Невизначеність переживає помилку: після «Перепитати» спершу звіримось
        $keep = !empty($st['uncertain']) ? ['uncertain' => true, 'sent' => $st['sent'] ?? 'doc', 'num' => $st['num'] ?? 0] : null;
        DB::update('fiscal_receipts', ['step' => $keep ? json_encode($keep) : null], 'id = ?', [(int)$r['id']]);
        $res = Fiscal::applyRaw((int)$r['id'], ['res' => max(1, $code), 'error' => $text]);
        return self::doneView($res['receipt'] ?? Fiscal::byId((int)$r['id']));
    }

    private static function wait(array $r, array $st, string $text): array
    {
        self::save($r, $st);
        DB::update('fiscal_receipts', ['error' => mb_substr($text, 0, 500)], 'id = ?', [(int)$r['id']]);
        return ['action' => 'wait', 'id' => (int)$r['id'], 'error' => $text];
    }

    private static function doneView(?array $r): array
    {
        if (!$r) return ['action' => 'done', 'state' => 'error', 'error' => 'Документ зник'];
        return [
            'action' => 'done', 'id' => (int)$r['id'],
            'state' => (string)$r['status'] === 'done' ? 'done' : 'error',
            'error' => (string)($r['error'] ?? ''),
            'number' => (string)($r['fiscal_number'] ?? ''),
            'label' => self::label($r),
            'test' => !empty($r['is_test']),
        ];
    }

    private static function own(int $id, int $userId): ?array
    {
        $r = Fiscal::byId($id);
        if (!$r || (string)$r['provider'] !== 'dps' || (string)$r['route'] !== 'device') return null;
        $mine = (int)$r['created_by_user_id'] === $userId
            || ($r['created_by_user_id'] === null && (string)$r['type'] === 'service');
        return $mine ? $r : null;
    }

    private static function store(array $r): array
    {
        return $r['store_id'] ? (DB::row('SELECT * FROM stores WHERE id = ?', [(int)$r['store_id']]) ?? []) : [];
    }

    private static function st(array $r): array
    {
        return json_decode((string)($r['step'] ?? ''), true) ?: [];
    }

    private static function save(array $r, array $st): void
    {
        DB::update('fiscal_receipts', ['step' => json_encode($st, JSON_UNESCAPED_UNICODE), 'updated_at' => now()],
            'id = ?', [(int)$r['id']]);
    }

    /** JSON з відповіді сервера (у т. ч. загорнутий у CMS); null — не JSON */
    private static function json(array $resp): ?array
    {
        if ($resp['status'] !== 200) return null;
        $j = json_decode(DpsDoc::toUtf8(Dps::content($resp['body'])), true);
        return is_array($j) ? $j : null;
    }
}
