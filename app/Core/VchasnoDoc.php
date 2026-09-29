<?php
declare(strict_types=1);

/**
 * Переклад нейтрального чека на мову «Вчасно.Каси».
 *
 * Це і є та частина, яку доведеться написати заново, якщо колись міняти
 * постачальника ПРРО, — і вона навмисно маленька. Усе, що коштувало думання
 * (розкладка знижки по копійках, податкові групи, округлення готівки, коли
 * пробивати й що робити з обірваним звʼязком), лишилось у Fiscal і від
 * постачальника не залежить.
 *
 * Хмара й Device Manager розмовляють майже однаково: обʼєкт fiscal у них той
 * самий, різниця лише в корені запиту. DM вимагає назву каси («device») і тип
 * завдання («type»), бо на одному ПК їх може бути кілька; хмарі це ні до чого —
 * там каса визначається токеном. Тому будівник один, а не два: дві копії
 * розʼїхались би на першій же правці.
 *
 * Контракт, який має виконати будь-який постачальник:
 *   body(array $doc, array $route): array   — запит
 *   parse(array $resp): array               — відповідь у наш вигляд
 */
class VchasnoDoc
{
    /** Нейтральне завдання → номер завдання у Вчасно */
    private const TASKS = [
        'shift_open'  => Vchasno::TASK_SHIFT_OPEN,
        'sell'        => Vchasno::TASK_SELL,
        'return'      => Vchasno::TASK_RETURN,
        'cash_in'     => Vchasno::TASK_CASH_IN,
        'cash_out'    => Vchasno::TASK_CASH_OUT,
        'x_report'    => Vchasno::TASK_X_REPORT,
        'shift_close' => Vchasno::TASK_Z_REPORT,
        'status'      => Vchasno::TASK_STATUS,
    ];

    public static function task(string $name): ?int
    {
        return self::TASKS[$name] ?? null;
    }

    /**
     * Запит до ПРРО.
     *
     * $doc — нейтральний документ (див. Fiscal::doc), $route — куди й чим
     * (див. FiscalProvider::route).
     */
    public static function body(array $doc, array $route): array
    {
        $task = self::task((string)($doc['task'] ?? ''));
        if ($task === null) return [];

        $fiscal = ['task' => $task];
        $cashier = Vchasno::cashierName((string)($doc['cashier'] ?? ''));
        if ($cashier !== '') $fiscal['cashier'] = $cashier;

        if (isset($doc['rows'])) {
            $receipt = [
                'sum' => Vchasno::money((float)($doc['sum'] ?? 0)),
                'rows' => array_map([self::class, 'row'], (array)$doc['rows']),
                'pays' => array_map([self::class, 'pay'], (array)($doc['pays'] ?? [])),
            ];
            // Округлення передаємо числом, а не прапорцем autoround: його
            // розуміє лише Device Manager, і чек, порахований по-різному в
            // двох маршрутах, — це рівно та розбіжність, якої тут не має бути.
            $round = Vchasno::money((float)($doc['round'] ?? 0));
            if (abs($round) >= 0.005) $receipt['round'] = $round;
            $footer = Vchasno::clean((string)($doc['comment_down'] ?? ''), 120);
            if ($footer !== '') $receipt['comment_down'] = $footer;
            $header = Vchasno::clean((string)($doc['comment_up'] ?? ''), 120);
            if ($header !== '') $receipt['comment_up'] = $header;
            $fiscal['receipt'] = $receipt;
        }

        if (isset($doc['cash'])) {
            $fiscal['cash'] = [
                'type' => (int)($doc['cash']['type'] ?? 0),
                'sum' => Vchasno::money((float)($doc['cash']['sum'] ?? 0)),
            ];
            $note = Vchasno::clean((string)($doc['cash']['comment'] ?? ''), 120);
            if ($note !== '') $fiscal['cash']['comment_down'] = $note;
        }

        $body = ['source' => 'BOFU', 'tag' => (string)($doc['tag'] ?? ''), 'fiscal' => $fiscal];

        $who = (array)($doc['customer'] ?? []);
        $info = [];
        if (($who['email'] ?? '') !== '') $info['email'] = $who['email'];
        if (($who['phone'] ?? '') !== '') $info['phone'] = $who['phone'];
        if ($info) $body['userinfo'] = $info;

        // Device Manager адресує завдання назвою каси всередині себе, а не
        // адресою: на одному ПК їх може стояти кілька. type = 1 означає
        // «виконати», type = 0 — «лише знайти за міткою», ним ми не
        // користуємось: повтор має або віддати вже пробитий чек, або пробити.
        if (($route['route'] ?? 'cloud') !== 'cloud') {
            $body = ['device' => (string)($route['device'] ?? ''), 'type' => 1] + $body;
        }
        return $body;
    }

    /** Куди слати запит саме цим маршрутом */
    public static function url(array $route): string
    {
        if (($route['route'] ?? 'cloud') === 'cloud') return Vchasno::API . '/api/v3/fiscal/execute';
        return rtrim((string)$route['url'], '/') . '/dm/execute';
    }

    /**
     * Відповідь ПРРО в наш вигляд.
     *
     * Однакова для хмари й DM: res = 0 означає «зроблено», решта лежить в info.
     * DM додає свої поля (task_status, resp_ver) — вони нам ні до чого, і саме
     * тому розбір один.
     *
     * @return array{ok:bool,error:string,res:int,receipt:array}
     */
    public static function parse(array $resp): array
    {
        if (!$resp) return ['ok' => false, 'error' => 'Каса не відповіла', 'res' => -1, 'receipt' => []];

        $res = array_key_exists('res', $resp) ? (int)$resp['res'] : 0;
        $info = (array)($resp['info'] ?? []);

        if ($res !== 0) {
            $error = trim((string)($resp['errortxt'] ?? ''));
            $extra = $resp['error_extra'] ?? null;
            if (is_array($extra) && $extra) {
                $bits = [];
                foreach ($extra as $k => $v) {
                    $bits[] = $k . ': ' . (is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE));
                }
                $error .= ($error !== '' ? ' (' : '(') . implode(', ', $bits) . ')';
            }
            if ($error === '') $error = 'Каса відмовила без пояснення (код ' . $res . ')';
            return ['ok' => false, 'error' => $error, 'res' => $res, 'receipt' => []];
        }

        $doccode = trim((string)($info['doccode'] ?? ''));
        return [
            'ok' => true, 'error' => '', 'res' => 0,
            'receipt' => $doccode === '' ? [] : [
                'fiscal_number' => $doccode,
                'rro_number' => (string)($info['fisid'] ?? ''),
                'shift_link' => isset($info['shift_link']) ? (int)$info['shift_link'] : null,
                'doc_no' => isset($info['docno']) ? (int)$info['docno'] : null,
                'dt' => (string)($info['dt'] ?? Vchasno::dt()),
                'qr' => (string)($info['qr'] ?? Vchasno::checkUrl($doccode)),
                'cancel_id' => (string)($info['cancelid'] ?? ''),
                'is_offline' => !empty($info['isoffline']),
                // dtype 0 — тестова каса: чеки без юридичної сили. Не помилка
                // налаштування (так і перевіряють інтеграцію), але бачити це
                // треба очима, а не здогадуватись.
                'is_test' => ((int)($info['dtype'] ?? 1)) === 0,
            ],
        ];
    }

    /** Стан ПРРО зі статусної відповіді — те, що показуємо на сторінці каси */
    public static function status(array $resp): array
    {
        $info = (array)($resp['info'] ?? []);
        return [
            'rro' => (string)($info['fisid'] ?? ''),
            'edrpou' => (string)($info['edrpou'] ?? ''),
            'shift' => isset($info['shift_status']) ? (int)$info['shift_status'] : -1,
            'shift_at' => (string)($info['shift_dt'] ?? ''),
            'online' => (int)($info['online_status'] ?? 0) === 1,
            'signed' => !isset($info['sign_status']) || (int)$info['sign_status'] >= 1,
            'test' => isset($info['isFis']) && (int)$info['isFis'] === 0,
            'safe' => (float)($info['safe'] ?? 0),
        ];
    }

    // ────────────────────────────────────────────────────────────── дрібниці

    private static function row(array $r): array
    {
        $out = [
            'name' => Vchasno::clean((string)($r['name'] ?? ''), 128),
            'cnt' => (float)($r['cnt'] ?? 0),
            'price' => Vchasno::money((float)($r['price'] ?? 0)),
            'disc' => Vchasno::money((float)($r['disc'] ?? 0)),
            'taxgrp' => (int)($r['taxgrp'] ?? 2),
        ];
        foreach (['code' => 64, 'code1' => 64, 'code2' => 32] as $key => $max) {
            $v = trim((string)($r[$key] ?? ''));
            if ($v !== '') $out[$key] = Vchasno::clean($v, $max);
        }
        return $out;
    }

    private static function pay(array $p): array
    {
        $out = ['type' => (int)($p['type'] ?? 0), 'sum' => Vchasno::money((float)($p['sum'] ?? 0))];
        // Решту не передаємо нулем: «решта 0.00» у чеку читається як помилка
        // касира, а не як точний розрахунок.
        $change = Vchasno::money((float)($p['change'] ?? 0));
        if ($change > 0) $out['change'] = $change;
        return $out;
    }
}
