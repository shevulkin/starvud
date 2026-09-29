<?php
/**
 * Роутер для вбудованого сервера PHP — локальний перегляд без Apache:
 *
 *   php -S 127.0.0.1:8090 bin/dev-router.php
 *
 * Робить те саме, що .htaccess: статичні файли з assets/ віддає як є, решту
 * веде в index.php. Службові теки закриває так само, як .htaccess, щоб
 * локально не звикнути до того, чого на сервері не буде.
 */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (preg_match('~^/(app|storage|bin|database|tests|\.git)(/|$)|/config|\.(sqlite|log|md|lock|yml|pem|key)$~i', $path)) {
    http_response_code(403);
    exit('Forbidden');
}
$file = dirname(__DIR__) . $path;
if ($path !== '/' && is_file($file) && !str_ends_with($file, '.php')) return false;
$_SERVER['SCRIPT_NAME'] = '/index.php';
require dirname(__DIR__) . '/index.php';
