<?php

/**
 * Router script for php -S local dev server.
 *
 * Mirrors production Nginx rewrites so that clean URLs (e.g. /blog/post-slug)
 * are forwarded to the correct front-controller even without Nginx.
 *
 * Usage: php -S localhost:8000 -t packages/lp packages/lp/router.php
 */

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($path === '/') {
    return false;
}

$file = $_SERVER['DOCUMENT_ROOT'] . $path;
if (is_file($file)) {
    return false;
}

if ($path === '/blog' || str_starts_with($path, '/blog/')) {
    include __DIR__ . '/blog/index.php';
    return;
}

if ($path === '/artists' || str_starts_with($path, '/artists/')) {
    include __DIR__ . '/artists/index.php';
    return;
}

if ($path === '/onboard' || str_starts_with($path, '/onboard/')) {
    include __DIR__ . '/onboard/index.php';
    return;
}

http_response_code(404);
echo '<!DOCTYPE html><html><head><title>404 Not Found</title></head><body><h1>404 Not Found</h1></body></html>';
return;
