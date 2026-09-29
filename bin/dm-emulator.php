<?php
declare(strict_types=1);

/**
 * Емулятор Device Manager — щоб не тестувати наосліп.
 *
 * Це НЕ ПРРО і не має до нього стосунку: він нічого не підписує й нікуди не
 * відправляє. Він робить рівно одне — поводиться з нашими запитами так, як
 * описано в документації Device Manager: приймає ті самі поля, перевіряє те
 * саме, відмовляє тими самими кодами й памʼятає мітки (tag), як вимагає
 * транзакційний режим.
 *
 * Навіщо: живої каси в тестах бути не може — кожен чек справжній, іде в ДПС і
 * скасовується лише поверненням. Але перевіряти треба саме те, ЩО МИ
 * НАДСИЛАЄМО: недобрана копійка валить чек посеред черги, а зайвий чек після
 * обриву звʼязку доводиться повертати руками. Емулятор ловить це на нашому
 * боці, ще до того, як хтось купить обладнання.
 *
 * Перевіряє він саме те, що ми можемо зіпсувати:
 *   — сума чека мусить дорівнювати сумі рядків після порядкових знижок;
 *   — сума оплат мусить відрізнятись від суми чека рівно на округлення;
 *   — податкова група має бути з переліку ДПС;
 *   — назви — лише з дозволеної абетки (решту, як і DM, замінює на «?»);
 *   — повтор із тією самою міткою НЕ створює другого чека.
 *
 * Запуск:
 *     php -S 127.0.0.1:3939 bin/dm-emulator.php
 *
 * Каси за замовчуванням: «kasa1» (фіскальна) і «test1» (тестова, dtype 0).
 * Перелік можна змінити змінною середовища DM_DEVICES.
 *
 * Спеціально для тестів у корені запиту приймається обʼєкт "emul":
 *   {"res": 1105}        — відповісти саме цією помилкою;
 *   {"silent": true}     — не відповісти взагалі (обірваний звʼязок);
 *   {"delay_ms": 1500}   — відповісти із затримкою.
 * Живий DM такого поля не має й на нього не зважає.
 */

$STATE = getenv('DM_STATE') ?: (dirname(__DIR__) . '/storage/dm-emulator.json');
$DEVICES = [];
foreach (explode(',', (string)(getenv('DM_DEVICES') ?: 'kasa1:1,test1:0')) as $d) {
    [$name, $fis] = array_pad(explode(':', trim($d)), 2, '1');
    if ($name !== '') $DEVICES[$name] = (int)$fis;   // 1 — фіскальна, 0 — тестова
}

// ─────────────────────────────────────────────────────────────────── стан

/** @return array{shifts:array,receipts:array,seq:array,safe:array} */
function dm_state(string $file): array
{
    $raw = is_file($file) ? (string)file_get_contents($file) : '';
    $s = json_decode($raw, true);
    return is_array($s) ? $s + ['shifts' => [], 'receipts' => [], 'seq' => [], 'safe' => []]
                        : ['shifts' => [], 'receipts' => [], 'seq' => [], 'safe' => []];
}

function dm_save(string $file, array $state): void
{
    $dir = dirname($file);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    @file_put_contents($file, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}

function dm_out(array $body): never
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Відповідь у формі Device Manager: те, що не стосується завдання, — сталі поля */
function dm_reply(array $req, int $res, string $err = '', array $info = [], array $extra = []): array
{
    return [
        'ver' => 6, 'resp_ver' => 4,
        'source' => (string)($req['source'] ?? ''),
        'device' => (string)($req['device'] ?? ''),
        'tag' => (string)($req['tag'] ?? ''),
        'task_status' => $res === 0 ? 2 : 3,
        'type' => (int)($req['type'] ?? 1),
        'task' => (int)($req['fiscal']['task'] ?? -1),
        'dt' => date('YmdHis') . '000',
        'res' => $res,
        'res_action' => $res === 0 ? 0 : ($res < 1100 ? 3 : 1),
        'errortxt' => $err,
        'warnings' => [],
    ] + ($info ? ['info' => $info] : []) + $extra;
}

/** Абетка ПРРО: усе інше DM замінює на «?» — і ми робимо так само, щоб це було видно в тестах */
function dm_clean(string $s): string
{
    $ok = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz'
        . 'АБВГДЕЁЖЗИЙКЛМНОПРСТУФХЦЧШЩЪЫЬЭЮЯабвгдеёжзийклмнопрстуфхцчшщъыьэюя'
        . 'ІіЇїҐґЄє !.,"№;:?*()<>|/@#$%^-_+=~\'&{}[]®©«»°±‘’“”–•—™„‰‹› 0123456789';
    $allow = [];
    foreach (preg_split('//u', $ok, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $c) $allow[$c] = true;
    $out = '';
    foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $c) $out .= $allow[$c] ?? false ? $c : '?';
    return $out;
}

// ─────────────────────────────────────────────────────────────── маршрути

$path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';

/*
 * Дозвіл браузеру звертатись сюди зі сторінки сайту.
 *
 * Потрібне лише маршруту «каса на цьому пристрої», де запит іде не з сервера,
 * а з вкладки продавця. Браузер спершу питає дозволу (preflight), і для
 * звертання з публічного сайту в локальну мережу вимагає ще й окремого
 * Access-Control-Allow-Private-Network.
 *
 * УВАГА: те, що так поводиться емулятор, НЕ доводить, що так поводиться живий
 * Device Manager. Це перше, що треба перевірити на справжньому DM, — і якщо
 * він цих заголовків не віддає, маршрут «на пристрої» з браузера не працює,
 * і лишається маршрут «каса точки» через агента.
 */
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Private-Network: true');
header('Access-Control-Max-Age: 600');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }

// Людині, яка відкрила адресу в браузері, — щоб було видно, що емулятор живий
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $state = dm_state($STATE);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Емулятор Device Manager\n\n";
    echo "Каси: " . implode(', ', array_map(
        fn($n, $f) => $n . ($f ? '' : ' (тестова)'), array_keys($DEVICES), $DEVICES)) . "\n";
    foreach ($DEVICES as $name => $fis) {
        $open = !empty($state['shifts'][$name]['open']);
        printf("  %-10s зміна %s, чеків %d, готівка %.2f\n", $name,
            $open ? 'відкрита' : 'закрита',
            count(array_filter($state['receipts'], fn($r) => $r['device'] === $name)),
            (float)($state['safe'][$name] ?? 0));
    }
    echo "\nПриймає POST на /dm/execute\n";
    exit;
}

if (!preg_match('~^/dm/execute(-prn|-pkg)?$~', $path)) {
    http_response_code(404);
    dm_out(['res' => 1404, 'errortxt' => 'Невідомий ендпоінт: ' . $path]);
}

$req = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($req)) dm_out(dm_reply([], 1400, 'Тіло запиту не JSON'));

// ── гачки для тестів ───────────────────────────────────────────────────
$emul = (array)($req['emul'] ?? []);
if (!empty($emul['delay_ms'])) usleep((int)$emul['delay_ms'] * 1000);
if (!empty($emul['silent'])) exit;                       // звʼязок обірвався
if (!empty($emul['res'])) {
    dm_out(dm_reply($req, (int)$emul['res'], (string)($emul['errortxt'] ?? 'Помилка на замовлення тесту')));
}

$device = trim((string)($req['device'] ?? ''));
if ($device === '' || !isset($DEVICES[$device])) {
    dm_out(dm_reply($req, 1002, 'ПРРО з назвою «' . $device . '» не знайдено в застосунку'));
}
$type = (int)($req['type'] ?? 1);
$tag = trim((string)($req['tag'] ?? ''));
$state = dm_state($STATE);

/*
 * Транзакційний режим. Головне, заради чого цей емулятор існує: повтор із тією
 * самою міткою мусить віддати ТОЙ САМИЙ чек, а не пробити другий. Саме на це
 * спирається наша обробка обірваного звʼязку.
 */
if ($tag !== '' && isset($state['receipts'][$tag])) {
    $saved = $state['receipts'][$tag]['response'];
    $saved['task_status'] = 2;
    dm_out($saved);
}
if ($type === 0) {
    dm_out(dm_reply($req, 1058, 'Чек з міткою ' . $tag . ' не знайдено'));
}

$fiscal = (array)($req['fiscal'] ?? []);
$task = isset($fiscal['task']) ? (int)$fiscal['task'] : -1;
$known = [0, 1, 2, 3, 4, 10, 11, 18];
if (!in_array($task, $known, true)) {
    dm_out(dm_reply($req, 1010, 'Завдання ' . $task . ' емулятор не підтримує'));
}

$shiftOpen = !empty($state['shifts'][$device]['open']);
$shiftNo = (int)($state['shifts'][$device]['no'] ?? 0);
$safe = (float)($state['safe'][$device] ?? 0);

// ── статус ПРРО ────────────────────────────────────────────────────────
if ($task === 18) {
    dm_out(dm_reply($req, 0, '', [
        'edrpou' => '55555555',
        'fisid' => '4000' . str_pad((string)crc32($device), 10, '0', STR_PAD_LEFT),
        'isFis' => $DEVICES[$device],
        'shift_status' => $shiftOpen ? 1 : 0,
        'shift_dt' => $shiftOpen ? (string)($state['shifts'][$device]['at'] ?? '') : '',
        'online_status' => 1,
        'sign_status' => 1,
        'safe' => round($safe, 2),
    ]));
}

// ── зміна ──────────────────────────────────────────────────────────────
if ($task === 0) {
    if ($shiftOpen) dm_out(dm_reply($req, 1020, 'Зміна вже відкрита'));
    $state['shifts'][$device] = ['open' => true, 'no' => $shiftNo + 1, 'at' => date('YmdHis')];
    dm_save($STATE, $state);
    dm_out(dm_reply($req, 0, '', ['task' => 0, 'shift_link' => $shiftNo + 1, 'safe' => round($safe, 2)]));
}
if ($task === 11) {
    if (!$shiftOpen) dm_out(dm_reply($req, 1021, 'Зміна вже закрита'));
    $state['shifts'][$device]['open'] = false;
    dm_save($STATE, $state);
    dm_out(dm_reply($req, 0, '', ['task' => 11, 'shift_link' => $shiftNo, 'safe' => round($safe, 2)]));
}
if ($task === 10) {
    dm_out(dm_reply($req, 0, '', ['task' => 10, 'shift_link' => $shiftNo, 'safe' => round($safe, 2)]));
}

// Чек на закритій зміні відкриває її сам — так само робить живий DM
if (in_array($task, [1, 2, 3, 4], true) && !$shiftOpen) {
    $shiftNo++;
    $state['shifts'][$device] = ['open' => true, 'no' => $shiftNo, 'at' => date('YmdHis')];
}

// ── внесення й видача ──────────────────────────────────────────────────
if ($task === 3 || $task === 4) {
    $sum = round((float)($fiscal['cash']['sum'] ?? 0), 2);
    if ($sum <= 0) dm_out(dm_reply($req, 1030, 'Сума внесення/видачі має бути більшою за нуль'));
    if ($task === 4 && $sum > $safe + 0.001) {
        dm_out(dm_reply($req, 1031, 'У касі немає такої суми готівки'));
    }
    $safe = round($safe + ($task === 3 ? $sum : -$sum), 2);
    $state['safe'][$device] = $safe;
    dm_save($STATE, $state);
    dm_out(dm_reply($req, 0, '', ['task' => $task, 'shift_link' => $shiftNo, 'safe' => $safe]));
}

// ── чек продажу й повернення ───────────────────────────────────────────
$receipt = (array)($fiscal['receipt'] ?? []);
$rows = (array)($receipt['rows'] ?? []);
if (!$rows) dm_out(dm_reply($req, 1040, 'У чеку немає жодного рядка'));

$rowsSum = 0.0;
foreach ($rows as $i => $r) {
    $cnt = (float)($r['cnt'] ?? 0);
    $price = round((float)($r['price'] ?? 0), 2);
    $disc = round((float)($r['disc'] ?? 0), 2);
    $tax = (int)($r['taxgrp'] ?? 0);
    if ($cnt <= 0) dm_out(dm_reply($req, 1041, 'Кількість у рядку ' . ($i + 1) . ' має бути більшою за нуль'));
    if ($tax < 1 || $tax > 9) {
        dm_out(dm_reply($req, 1042, 'Невідома податкова група в рядку ' . ($i + 1), [],
            ['error_extra' => ['row' => $i + 1, 'taxgrp' => $tax]]));
    }
    $line = round($cnt * $price, 2);
    if ($disc > $line + 0.001) {
        dm_out(dm_reply($req, 1043, 'Знижка більша за суму рядка ' . ($i + 1), [],
            ['error_extra' => ['row' => $i + 1, 'line' => $line, 'disc' => $disc]]));
    }
    $rowsSum = round($rowsSum + $line - $disc, 2);
    // Живий DM мовчки замінює недозволені символи; лишаємо це видимим у чеку,
    // щоб тест міг довести, що ми чистимо назви ще в себе
    $rows[$i]['name'] = dm_clean((string)($r['name'] ?? ''));
}

$sum = round((float)($receipt['sum'] ?? 0), 2);
if (abs($sum - $rowsSum) >= 0.005) {
    dm_out(dm_reply($req, 1001, 'Сума всіх позицій товару відрізняється від загальної суми чеку', [],
        ['error_extra' => ['sum' => (int)round($sum * 100), 'rows_sum' => (int)round($rowsSum * 100)]]));
}

$round = round((float)($receipt['round'] ?? 0), 2);
$paysSum = 0.0;
foreach ((array)($receipt['pays'] ?? []) as $p) $paysSum = round($paysSum + (float)($p['sum'] ?? 0), 2);
if (abs($paysSum - round($sum + $round, 2)) >= 0.005) {
    dm_out(dm_reply($req, 1003, 'Сума оплат не відповідає сумі чеку з урахуванням округлення', [],
        ['error_extra' => ['pays' => (int)round($paysSum * 100),
                           'expected' => (int)round(($sum + $round) * 100)]]));
}

// Готівка змінює вміст скриньки; решта — ні
foreach ((array)($receipt['pays'] ?? []) as $p) {
    if ((int)($p['type'] ?? 0) !== 0) continue;
    $cash = round((float)($p['sum'] ?? 0), 2);
    $safe = round($safe + ($task === 1 ? $cash : -$cash), 2);
}
$state['safe'][$device] = $safe;

$seq = (int)($state['seq'][$device] ?? 0) + 1;
$state['seq'][$device] = $seq;
$fis = $DEVICES[$device];
$doccode = ($fis ? '' : 'TEST_') . strtoupper(substr(hash('sha256', $device . $seq . microtime()), 0, 16));
$rroNumber = '4000' . str_pad((string)crc32($device), 10, '0', STR_PAD_LEFT);

$response = dm_reply($req, 0, '', [
    'task' => $task,
    'fisid' => $rroNumber,
    'dataid' => $seq,
    'doccode' => $doccode,
    'dt' => date('YmdHis'),
    'cashier' => (string)($fiscal['cashier'] ?? ''),
    'dtype' => $fis,                 // 0 — тестова каса, чек без юридичної сили
    'isprint' => 0,
    'isoffline' => false,
    'safe' => $safe,
    'shift_link' => $shiftNo ?: 1,
    'docno' => $seq,
    'cancelid' => $doccode,
    'qr' => 'https://kasa.vchasno.ua/c/' . $doccode,
    'mac' => hash('sha256', $doccode),
]);

if ($tag !== '') {
    $state['receipts'][$tag] = ['device' => $device, 'response' => $response, 'at' => date('c')];
}
dm_save($STATE, $state);
dm_out($response);
