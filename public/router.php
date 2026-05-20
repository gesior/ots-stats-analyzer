<?php

declare(strict_types=1);

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');

if ($uri === '/api.php' || str_starts_with($uri, '/api.php?')) {
    require dirname(__DIR__) . '/api.php';

    return true;
}

$publicDir = __DIR__;
$file = $publicDir . $uri;

if ($uri !== '/' && is_file($file)) {
    return false;
}

require $publicDir . '/index.html';

return true;
