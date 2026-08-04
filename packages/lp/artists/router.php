<?php

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (preg_match('#^/artists/(.+)$#', $uri, $m)) {
    $_SERVER['REQUEST_URI']  = '/' . $m[1];
    $_SERVER['SCRIPT_NAME']  = '/index.php';
    $_SERVER['PHP_SELF']     = '/index.php/' . $m[1];
    require __DIR__ . '/index.php';
    return true;
}

if ($uri === '/artists' || $uri === '/artists/') {
    $_SERVER['REQUEST_URI']  = '/';
    $_SERVER['SCRIPT_NAME']  = '/index.php';
    $_SERVER['PHP_SELF']     = '/index.php';
    require __DIR__ . '/index.php';
    return true;
}

return false;
