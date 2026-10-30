<?php

/**
 * Artist intake page — /novo-artista/<token>
 *
 * Invite-only. The token is minted in the admin panel (/artistas-admin/<token>)
 * and handed to a single artist. Without a valid, unused token the form does
 * not render and submissions are rejected.
 */

declare(strict_types=1);

// Token pages must never be cached or leak the token via Referer.
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');

require_once __DIR__ . '/../lib/artists/store.php';
require_once __DIR__ . '/../lib/artists/intake-form.php';

function intake_page_head(string $title): void
{
    echo '<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . ' — VAIF</title>
    <link rel="icon" href="/img/favicon/favicon.ico" sizes="any" type="image/x-icon">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;600;700&family=Montserrat:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/novo-artista/form.css">
</head>
<body>';
}

function intake_page_invalid(string $heading, string $message): void
{
    intake_page_head('Link Inválido');
    echo '<main class="intake-shell">
        <div class="intake-confirmation">
            <div class="confirm-icon" style="color:#f87171;border-color:#f87171;background:#2a0a0a;">&#9888;</div>
            <h2>' . htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') . '</h2>
            <p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>
            <a href="/" class="btn-submit">Voltar ao site</a>
        </div>
    </main>
</body>
</html>';
    exit;
}

// ================================================================
// Token validation
// ================================================================

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$token = '';
if (preg_match('#^/novo-artista/([A-Za-z0-9_-]+)$#', $path, $m)) {
    $token = $m[1];
}

if ($token === '') {
    intake_page_invalid('Link inválido', 'Este link de cadastro não é válido. Peça um novo link à equipe VAIF.');
}

$pdo = artist_db();
if ($pdo === null) {
    intake_page_invalid('Em manutenção', 'O cadastro está temporariamente indisponível. Tente novamente em alguns minutos.');
}

if (!intake_token_is_active($pdo, $token)) {
    intake_page_invalid('Link inválido ou já utilizado', 'Este link já foi usado ou não existe. Peça um novo link à equipe VAIF.');
}

// ================================================================
// Render the form
// ================================================================

$formHtml = render_artist_intake_form([], 'create', 0, '', $token);

intake_page_head('Cadastro de Artista');
echo '<main class="intake-shell">' . $formHtml . '</main>
<script src="/novo-artista/form.js" defer></script>
</body>
</html>';
