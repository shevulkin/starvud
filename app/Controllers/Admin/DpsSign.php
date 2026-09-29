<?php
declare(strict_types=1);

namespace Controllers\Admin;

use DB, Auth, DpsFlow, Dps, Fiscal, RateLimit;

/**
 * Вкладка з ключем касира (ПРРО ДПС): звідси вона бере, що підписати, і сюди
 * віддає підписане.
 *
 * Ключ і пароль сюди не приходять ніколи — лише готові підписи. Тому ці
 * маршрути нічого не знають про КЕП: вони видають документ і пересилають
 * підписаний документ на фіскальний сервер (див. DpsFlow).
 */
class DpsSign
{
    /** Черга цієї людини; заодно «пульс» — вкладка з ключем жива */
    public static function jobs(): never
    {
        Auth::requireCap('orders.fiscal');
        $uid = (int)Auth::id();
        if (!empty($_POST['signer'])) DpsFlow::heartbeat($uid);
        $parentId = (int)($_POST['parent_id'] ?? 0) ?: null;
        $jobs = [];
        foreach (DpsFlow::jobsFor($uid, $parentId) as $r) {
            $jobs[] = ['id' => (int)$r['id'], 'label' => DpsFlow::label($r), 'parent_id' => (int)$r['parent_id']];
        }
        json_response(['ok' => true, 'jobs' => $jobs]);
    }

    public static function step(): never
    {
        Auth::requireCap('orders.fiscal');
        json_response(DpsFlow::step((int)($_POST['id'] ?? 0), (int)Auth::id(), self::tab()));
    }

    public static function submit(): never
    {
        Auth::requireCap('orders.fiscal');
        // Кожен підпис — справжній документ у ДПС; ліміт тут не від ботів, а
        // від зациклення вкладки
        RateLimit::guard('dps_submit', 600, 3600, null, true);
        json_response(DpsFlow::submit((int)($_POST['id'] ?? 0), (int)Auth::id(), self::tab(),
            (string)($_POST['purpose'] ?? ''), (string)($_POST['signed'] ?? '')));
    }

    /** Стан чеків замовлення й чи є кому їх підписати — для картки замовлення */
    public static function status(): never
    {
        Auth::requireCap('orders.fiscal');
        $uid = (int)Auth::id();
        $parentId = (int)($_POST['parent_id'] ?? 0);
        $left = $parentId ? count(DpsFlow::jobsFor($uid, $parentId)) : count(DpsFlow::jobsFor($uid));
        $busy = $parentId ? (int)DB::val("SELECT COUNT(*) FROM fiscal_receipts WHERE parent_id = ? AND provider = 'dps' AND status = 'pending'", [$parentId]) : 0;
        json_response(['ok' => true, 'left' => $left, 'busy' => $busy, 'signer' => DpsFlow::signerAlive($uid)]);
    }

    /** Ідентифікатор вкладки: так дві вкладки не підписують один документ разом */
    private static function tab(): string
    {
        return substr(preg_replace('/[^a-zA-Z0-9]/', '', (string)($_POST['tab'] ?? '')), 0, 40) ?: 'tab';
    }

    /**
     * Проксі до ЦСК для бібліотеки підпису.
     *
     * Бібліотека в браузері не може сама звернутись до серверів ЦСК (CORS і
     * політика безпеки сайту пускають запити лише на наш домен), тож шле їх
     * сюди: ?address=<хост/шлях>&contentType=<тип>, тіло — base64. Через проксі
     * ходять лише запити сертифікатів, їхнього статусу й позначки часу —
     * ключа й пароля в них немає.
     *
     * Щоб маршрут не став відкритим проксі, пускаємо лише до хостів зі списку
     * ЦСК, лише http/https, без переадресацій і з обмеженням розміру.
     * CSRF тут не перевіряється навмисно: запити шле воркер бібліотеки, він
     * токена не має, а нашкодити цим маршрутом нема чим.
     */
    public static function proxy(): never
    {
        if (!Auth::isStaff() || !Auth::can('orders.fiscal')) self::bare(403, 'Forbidden');
        RateLimit::guard('ca_proxy', 1200, 3600, null, true);
        $address = qs('address');
        $host = Dps::caHost($address);
        if ($host === '' || !isset(Dps::caHosts()[$host])) {
            error_log('КЕП-проксі: відхилено адресу ' . mb_substr($address, 0, 120));
            self::bare(403, 'Address not allowed');
        }
        $url = str_contains($address, '://') ? $address : 'http://' . $address;
        $method = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? 'POST' : 'GET';
        $body = null;
        if ($method === 'POST') {
            $raw = (string)file_get_contents('php://input', false, null, 0, 2 * 1024 * 1024);
            $body = base64_decode(trim($raw), true);
            if ($body === false) self::bare(400, 'Bad request body');
            if (strlen($body) > 1024 * 1024) self::bare(413, 'Request too large');
        }
        $type = qs('contentType');
        $headers = preg_match('~^[\w.+-]+/[\w.+-]+$~', $type) ? ['Content-Type: ' . $type] : [];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => array_merge($headers, ['Expect:']),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_MAXFILESIZE => 10 * 1024 * 1024,
        ]);
        $resp = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($resp === false || $status !== 200) {
            error_log('КЕП-проксі: ЦСК ' . $host . ' відповів ' . ($resp === false ? 'помилкою зʼєднання' : $status));
            self::bare(502, 'CA error');
        }
        header('Content-Type: X-user/base64-data');
        header('Cache-Control: no-store');
        echo base64_encode((string)$resp);
        exit;
    }

    private static function bare(int $code, string $text): never
    {
        http_response_code($code);
        header('Content-Type: text/plain; charset=utf-8');
        echo $text;
        exit;
    }
}
