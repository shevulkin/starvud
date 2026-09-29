<?php
declare(strict_types=1);
define('BOFU_START', microtime(true));
define('BOFU_ROOT', __DIR__);
require __DIR__ . '/app/Core/bootstrap.php';
App::run();
