<?php
/**
 * sdr-admin front controller — routes /onboard/<token> and /agenda/<token>
 * to the standalone beta pages. No framework, PHP 8+.
 */

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($path === '/onboard/sucesso' || str_starts_with($path, '/onboard/')) {
    include __DIR__ . '/onboard/index.php';
    return;
}

if ($path === '/agenda' || str_starts_with($path, '/agenda/')) {
    include __DIR__ . '/agenda/index.php';
    return;
}

http_response_code(404);
echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8"><title>404</title></head><body><h1>Não encontrado</h1></body></html>';
return;