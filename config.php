<?php
/**
 * Базова конфігурація «Старвуд-М» — інтернет-магазину дитячих іграшок.
 * НЕ редагуйте цей файл — створіть config.local.php і перевизначте потрібні ключі.
 */
$config = [
    'app_name'   => 'Старвуд-М',
    // Префікс номерів замовлень: SV-260929-A3F2. Короткий, латиницею — його
    // диктують по телефону й друкують на накладній.
    'order_prefix' => 'SV',
    'env'        => 'production',      // production | dev
    'debug'      => false,
    'base_url'   => null,              // null = автовизначення (працює і на /starvud/, і в корені домену)
    // База даних: mysql (Docker/XAMPP/HostIQ) або sqlite (тести)
    'db' => [
        'driver'   => 'mysql',
        'host'     => '127.0.0.1',
        'port'     => 3308,            // MySQL у Docker (3306 — XAMPP, 3307 — bofu)
        'database' => 'starvud',
        'username' => 'starvud',
        // Пароль — лише в config.local.php (у git не потрапляє), як і на сервері
        'password' => '',
        'charset'  => 'utf8mb4',
        'sqlite_path' => __DIR__ . '/storage/database.sqlite',
    ],
    // Google OAuth (заповнюється в налаштуваннях адмінки або тут)
    'google' => [ 'client_id' => '', 'client_secret' => '' ],
    // Демо-входу більше немає. Він давав адмін-права одним POST без пароля, а
    // стримував його рівно цей рядок — тобто випадково скопійований на сервер
    // config.local.php віддавав магазин чужому, і ніщо про це не попереджало.
    // Локально права видає `php bin/cli.php grant-admin <email>`.
    // Увімкніть, лише якщо сайт стоїть за Cloudflare/балансувальником: тоді IP клієнта
    // беремо з X-Forwarded-For. Без проксі це дало б змогу обходити ліміти підробкою заголовка.
    'trust_proxy' => false,
    // HSTS: заборонити браузеру ходити на цей домен по http. Вмикати ЛИШЕ коли
    // HTTPS вже працює й на решті сайтів домену — заголовок діє на весь домен і
    // памʼятається браузером місяцями, тож помилка тут не відкочується швидко.
    'hsts' => false,
    'uploads_dir' => __DIR__ . '/assets/uploads',
    'session_name' => 'starvud_sid',
];
if (is_file(__DIR__ . '/config.local.php')) {
    $local = require __DIR__ . '/config.local.php';
    $config = array_replace_recursive($config, $local);
}
return $config;
