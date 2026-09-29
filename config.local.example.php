<?php
/**
 * Шаблон config.local.php для БОЙОВОГО сервера.
 *
 * Скопіюйте цей файл на сервері як config.local.php (поруч із config.php) і
 * впишіть доступи до бази, які видав хостинг. Сам config.local.php у git не
 * потрапляє, а назовні його не віддає .htaccess.
 */
return [
    'env'   => 'production',
    'debug' => false,          // true показує помилки відвідувачам — на сервері лише false

    'db' => [
        'driver'   => 'mysql',
        'host'     => 'localhost',
        'port'     => 3306,
        'database' => 'ІМʼЯ_БАЗИ',
        'username' => 'КОРИСТУВАЧ_БАЗИ',
        'password' => 'ПАРОЛЬ_БАЗИ',
        'charset'  => 'utf8mb4',
    ],

    // Увімкніть, коли https://ваш-домен відкривається без попереджень і на
    // всіх піддоменах теж: браузер запамʼятає «лише https» на пів року.
    'hsts' => false,

    // Лише якщо сайт стоїть за Cloudflare чи іншим проксі
    'trust_proxy' => false,
];
