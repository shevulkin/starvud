<?php
declare(strict_types=1);

/**
 * Агент каси — те саме, що vchasno-agent.ps1, але для Linux і для тих, у кого
 * на касовому ПК уже є PHP.
 *
 * Навіщо він є. Ключ підпису лежить у магазині — на флешці або в папці, — і
 * касу (Device Manager) навмисно не видно з інтернету. Тому не сайт стукає до
 * каси, а агент стукає до сайту: назовні в магазині не відкрито жодного порту,
 * а телефон продавця може працювати хоч із дому — він говорить лише з сайтом.
 *
 * Агент нічого не знає ні про формат чека, ні про постачальника ПРРО: він
 * бере готове тіло запиту, несе його на касу й повертає відповідь як є.
 * Обірваний звʼязок не страшний — у кожного завдання незмінна мітка (tag), і
 * повтор із нею каса впізнає як ту саму спробу.
 *
 * Запуск:
 *   php vchasno-agent.php --site=https://ваш-сайт.ua --token=ag_... [--dm=http://localhost:3939]
 *
 * Прапорці:
 *   --once      один прохід і вихід (для cron і для перевірки)
 *   --interval  пауза між проходами, секунд (типово 3)
 *   --quiet     писати лише про чеки й помилки
 *
 * Постійна робота: systemd-юніт із Restart=always або cron із --once щохвилини.
 */

$opt = getopt('', ['site:', 'token:', 'dm::', 'interval::', 'once', 'quiet']);
$configFile = __DIR__ . '/agent.config.json';
$cfg = is_file($configFile) ? (array)json_decode((string)file_get_contents($configFile), true) : [];

$site = rtrim((string)($opt['site'] ?? $cfg['Site'] ?? ''), '/');
$token = (string)($opt['token'] ?? $cfg['Token'] ?? '');
$dm = rtrim((string)($opt['dm'] ?? $cfg['Dm'] ?? ''), '/');
$interval = max(1, (int)($opt['interval'] ?? $cfg['Interval'] ?? 3));
$once = isset($opt['once']);
$quiet = isset($opt['quiet']);

if ($site === '' || $token === '') {
    fwrite(STDERR, "Потрібні адреса сайту й токен точки.\n"
        . "  php vchasno-agent.php --site=https://ваш-сайт.ua --token=ag_...\n"
        . "Токен беруть в адмінці: Магазини → картка точки → «Показати токен».\n");
    exit(1);
}

/** @return array{code:int,json:?array,error:string} */
function agent_post(string $url, array $body, int $timeout): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false) return ['code' => 0, 'json' => null, 'error' => $err ?: 'немає відповіді'];
    $json = json_decode((string)$raw, true);
    return ['code' => $code, 'json' => is_array($json) ? $json : null,
            'error' => is_array($json) ? '' : 'відповідь не JSON'];
}

function agent_log(string $text, bool $always = true): void
{
    global $quiet;
    if (!$always && $quiet) return;
    $line = date('Y-m-d H:i:s') . '  ' . $text . "\n";
    echo $line;
    @file_put_contents(__DIR__ . '/agent.log', $line, FILE_APPEND);
}

agent_log('Агент запущено. Сайт: ' . $site . ($dm !== '' ? ', каса: ' . $dm : ''));

$backoff = $interval;
while (true) {
    $pull = agent_post($site . '/api/fiscal/pull', ['token' => $token, 'limit' => 5], 20);
    if ($pull['json'] === null || empty($pull['json']['ok'])) {
        agent_log('Сайт не дав завдань: ' . ($pull['json']['error'] ?? $pull['error'] ?: ('HTTP ' . $pull['code'])));
        if ($once) exit(1);
        sleep($backoff = min($backoff * 2, 60));
        continue;
    }
    $backoff = $interval;

    foreach ((array)$pull['json']['jobs'] as $job) {
        // Адреса з завдання, але місцеве налаштування важливіше: на самому ПК
        // зручніше localhost, хоч би що стояло в адмінці.
        $url = $dm !== '' ? $dm . '/dm/execute' : (string)($job['url'] ?? '');
        if ($url === '') { agent_log('Завдання ' . $job['id'] . ': невідомо, куди слати'); continue; }

        agent_log('Завдання ' . $job['id'] . ' → ' . $url, false);
        $answer = agent_post($url, (array)$job['body'], (int)($job['timeout'] ?? 25));
        // Порожня відповідь — навмисно: сайт позначить чек непевним і поверне
        // завдання в чергу. Другого чека з цього не вийде — мітка та сама.
        $response = $answer['json'] ?? [];
        if ($answer['json'] === null) agent_log('Каса не відповіла: ' . $answer['error']);

        $push = agent_post($site . '/api/fiscal/push',
            ['token' => $token, 'id' => (int)$job['id'], 'response' => $response], 20);
        $state = (string)($push['json']['state'] ?? '');
        if ($state === 'done') agent_log('Завдання ' . $job['id'] . ': чек пробито');
        elseif ($state === 'error') agent_log('Завдання ' . $job['id'] . ': каса відмовила — ' . ($push['json']['error'] ?? ''));
        else agent_log('Завдання ' . $job['id'] . ': відповіді немає, спробуємо ще');
    }

    if ($once) break;
    sleep($interval);
}
