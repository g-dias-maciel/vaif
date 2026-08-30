<?php
/**
 * Agenda admin portal — vaif.com.br/agenda/<token>
 * Self-serve page where artists see their upcoming availability (derived from
 * weekly working hours, in their own timezone) and block/unblock time ranges so
 * off-days and personal appointments don't get booked by Beatriz.
 *
 * Ticket: #30 — Artist agenda admin page
 * Dependencies: #27 — Availability from working hours (60-min slots),
 *               #29 — n8n webhook for availability + blocking
 */

declare(strict_types=1);

// --- Helpers (same pattern as the onboarding portal) ---

function call_n8n(string $url, array $data = []): ?array
{
    if ($url === '') {
        return null;
    }
    $ctx = stream_context_create([
        'http' => [
            'method'  => empty($data) ? 'GET' : 'POST',
            'header'  => "Content-Type: application/json\r\n",
            'content' => empty($data) ? null : json_encode($data),
            'timeout' => 10,
        ],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        return null;
    }
    return json_decode($body, true) ?: null;
}

function render_html(string $title, string $body, string $extra_head = ''): string
{
    return <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>$title — VAIF</title>
    <link rel="icon" type="image/x-icon" href="/img/favicon.ico">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;600;700&family=Montserrat:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            background: radial-gradient(ellipse at top, #1a1a1a 0%, #0A0A0A 70%);
            color: rgb(242, 237, 228);
            font-family: 'Montserrat', sans-serif;
            min-height: 100vh;
            display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            padding: 24px 16px;
        }
        .card {
            background: #121212;
            border: 1px solid #222222;
            border-radius: 16px;
            padding: 40px 32px;
            max-width: 560px; width: 100%;
        }
        .card--center { text-align: center; }
        h1 {
            font-family: 'Cormorant Garamond', serif;
            font-size: 2rem; font-weight: 600;
            color: rgb(242, 237, 228); margin-bottom: 12px;
        }
        h2 {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.25rem; font-weight: 400;
            color: rgb(160, 154, 142); margin-bottom: 24px; line-height: 1.5;
        }
        .divider { color: #D4B04C; font-size: 1rem; margin: 24px 0; letter-spacing: 8px; text-align: center; }
        .section-title {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.15rem; font-weight: 600;
            color: rgb(242, 237, 228); margin-bottom: 12px;
            text-transform: uppercase; letter-spacing: 1.5px;
        }
        .section-sub { font-size: 0.8rem; color: rgb(160, 154, 142); margin-bottom: 16px; }
        .slot-list { list-style: none; display: flex; flex-direction: column; gap: 8px; }
        .slot-list li {
            display: flex; align-items: center; justify-content: space-between;
            gap: 12px; padding: 12px 16px;
            background: #1a1a1a; border: 1px solid #222222; border-radius: 10px;
            font-size: 0.9rem;
        }
        .slot-time { color: rgb(242, 237, 228); font-weight: 600; }
        .slot-empty {
            color: rgb(160, 154, 142); font-size: 0.9rem;
            padding: 16px 4px; text-align: center;
        }
        .btn {
            display: inline-block; background: #D4B04C; color: #0A0A0A;
            padding: 10px 20px; border-radius: 8px; text-decoration: none;
            border: none; cursor: pointer;
            font-family: 'Montserrat', sans-serif;
            font-weight: 700; font-size: 0.78rem; letter-spacing: 1px;
            text-transform: uppercase;
        }
        .btn:hover { opacity: 0.9; }
        .btn--ghost {
            background: transparent; color: rgb(242, 237, 228);
            border: 1px solid #D4B04C; padding: 8px 16px;
        }
        .btn--ghost:hover { background: #D4B04C; color: #0A0A0A; }
        .btn--danger {
            background: transparent; color: #f87171;
            border: 1px solid #f87171; padding: 8px 16px;
        }
        .btn--danger:hover { background: #f87171; color: #0A0A0A; }
        .block-form { display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; }
        .field { display: flex; flex-direction: column; gap: 6px; flex: 1; min-width: 120px; }
        .field label { font-size: 0.72rem; letter-spacing: 0.5px; text-transform: uppercase; color: rgb(160, 154, 142); }
        .field input {
            background: #0A0A0A; border: 1px solid #222222; border-radius: 8px;
            color: rgb(242, 237, 228); padding: 10px 12px;
            font-family: 'Montserrat', sans-serif; font-size: 0.9rem;
        }
        .field input:focus { outline: none; border-color: #D4B04C; }
        .status-badge {
            display: inline-block; padding: 8px 20px; border-radius: 20px;
            font-size: 0.85rem; font-weight: 600; letter-spacing: 0.5px; margin: 16px 0;
        }
        .status-success { background: #0a2a0a; color: #4ade80; border: 1px solid #4ade80; }
        .status-error { background: #2a0a0a; color: #f87171; border: 1px solid #f87171; }
        .status-waiting { background: #1a1a1a; color: #D4B04C; border: 1px solid #D4B04C; }
        .icon-large { font-size: 3rem; margin-bottom: 16px; text-align: center; }
        .inline-form { display: inline; }
        .footer-link { display: block; text-align: center; margin-top: 28px; font-size: 0.85rem; color: rgb(160, 154, 142); }
        .footer-link a { color: #D4B04C; text-decoration: none; }
    </style>
    $extra_head
</head>
<body>$body</body>
</html>
HTML;
}

function fmt_datetime(string $iso, string $tz): string
{
    try {
        $dt = new DateTimeImmutable($iso);
        return $dt->setTimezone(new DateTimeZone($tz))->format('d/m/Y H:i');
    } catch (Throwable $e) {
        return $iso;
    }
}

function to_utc_iso(string $date, string $time, string $tz): string
{
    try {
        $dt = new DateTimeImmutable("$date $time", new DateTimeZone($tz));
        return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    } catch (Throwable $e) {
        return '';
    }
}

// ================================================================
// Routes
// ================================================================

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

// Extract token from /agenda/<token>
$token = null;
if (preg_match('#^/agenda/([a-z0-9]{8,64})$#i', $path, $m)) {
    $token = $m[1];
}

// Route: missing/invalid
if ($token === null || $token === '') {
    $body = <<<HTML
    <div class="card card--center">
        <div class="icon-large">&#x26A0;</div>
        <h1>Link inválido</h1>
        <h2>O link da agenda não foi encontrado. Verifique a URL ou solicite um novo link ao parceiro VAIF.</h2>
        <a href="/" class="btn">Voltar ao site</a>
    </div>
HTML;
    echo render_html('Link Inválido', $body);
    exit;
}

$webhook_url = getenv('N8N_AGENDA_WEBHOOK_URL') ?: '';
if ($webhook_url === '') {
    $body = <<<HTML
    <div class="card card--center">
        <div class="icon-large">&#x26A0;</div>
        <h1>Em manutenção</h1>
        <h2>A agenda está temporariamente indisponível. Tente novamente em alguns minutos.</h2>
        <a href="/" class="btn">Voltar ao site</a>
    </div>
HTML;
    echo render_html('Indisponível', $body);
    exit;
}

// ================================================================
// Handle POST actions (block / unblock) before rendering
// ================================================================

$flash = null; // { type: 'success'|'error', text }

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';
    $payload = ['token' => $token, 'action' => $action];

    if ($action === 'block') {
        $start_at = (string) ($_POST['start_at'] ?? '');
        $end_at   = (string) ($_POST['end_at'] ?? '');
        if ($start_at === '' || $end_at === '') {
            $tz = $_POST['timezone'] ?? 'UTC';
            $start_at = to_utc_iso((string) ($_POST['block_date'] ?? ''), (string) ($_POST['block_start'] ?? ''), $tz);
            $end_at   = to_utc_iso((string) ($_POST['block_date'] ?? ''), (string) ($_POST['block_end'] ?? ''), $tz);
        }
        $payload['start_at'] = $start_at;
        $payload['end_at']   = $end_at;
    } elseif ($action === 'unblock') {
        $payload['block_id'] = (string) ($_POST['block_id'] ?? '');
    }

    $result = call_n8n($webhook_url, $payload);

    if ($result === null) {
        $msg = htmlspecialchars($result['error'] ?? 'Este link expirou ou já foi utilizado. Solicite um novo link de agenda ao parceiro VAIF.');
        $body = <<<HTML
        <div class="card card--center">
            <div class="icon-large">&#x26A0;</div>
            <h1>Link inválido ou expirado</h1>
            <h2>$msg</h2>
            <a href="/" class="btn">Voltar ao site</a>
        </div>
HTML;
        echo render_html('Link Expirado', $body);
        exit;
    }

    if ($action === 'block') {
        $flash = ($result['success'] ?? false)
            ? ['type' => 'success', 'text' => 'Período bloqueado com sucesso.']
            : ['type' => 'error',   'text' => htmlspecialchars($result['message'] ?? 'Não foi possível bloquear o período.')];
    } elseif ($action === 'unblock') {
        $flash = ($result['success'] ?? false)
            ? ['type' => 'success', 'text' => 'Período desbloqueado com sucesso.']
            : ['type' => 'error',   'text' => htmlspecialchars($result['message'] ?? 'Não foi possível desbloquear o período.')];
    }
}

// ================================================================
// Load availability (also validates the token)
// ================================================================

$data = call_n8n($webhook_url, ['token' => $token, 'action' => 'list']);

if ($data === null || !($data['success'] ?? false)) {
    $msg = htmlspecialchars($data['error'] ?? 'Este link expirou ou já foi utilizado. Solicite um novo link de agenda ao parceiro VAIF.');
    $body = <<<HTML
    <div class="card card--center">
        <div class="icon-large">&#x26A0;</div>
        <h1>Link inválido ou expirado</h1>
        <h2>$msg</h2>
        <a href="/" class="btn">Voltar ao site</a>
    </div>
HTML;
    echo render_html('Link Expirado', $body);
    exit;
}

// ================================================================
// Render agenda
// ================================================================

$artist_name = htmlspecialchars($data['artist_name'] ?? 'Artista');
$tz = (string) ($data['timezone'] ?? 'UTC');
$tz = $tz !== '' ? $tz : 'UTC';

$blocks    = is_array($data['blocks'] ?? null) ? $data['blocks'] : [];
$blocks_count    = count($blocks);

// Blocks list
$blocks_html = '';
if (count($blocks) === 0) {
    $blocks_html = '<p class="slot-empty">Nenhum horário bloqueado.</p>';
} else {
    $items = '';
    foreach ($blocks as $block) {
        $id  = htmlspecialchars((string) ($block['id'] ?? ''));
        $st  = fmt_datetime((string) ($block['start_at'] ?? ''), $tz);
        $en  = fmt_datetime((string) ($block['end_at'] ?? ''), $tz);
        $items .= <<<LI
        <li id="block-$id" class="block-item">
            <span class="slot-time">$st – $en</span>
            <form method="post" class="inline-form">
                <input type="hidden" name="action" value="unblock">
                <input type="hidden" name="block_id" value="$id">
                <button type="submit" class="btn btn--danger">Desbloquear</button>
            </form>
        </li>
LI;
    }
    $blocks_html = '<ul class="slot-list">' . $items . '</ul>';
}

// Flash message
$flash_html = '';
if ($flash !== null) {
    $badge_class = $flash['type'] === 'success' ? 'status-success' : 'status-error';
    $flash_html = '<div class="status-badge ' . $badge_class . '" style="display:block;">' . $flash['text'] . '</div>';
}

$body = <<<HTML
<div class="card">
    <h1>Agenda de $artist_name</h1>
    <h2>Gerencie sua disponibilidade. Bloqueie horários para compromissos pessoais — a Beatriz não agenda clientes nos períodos bloqueados.</h2>

    <div class="divider">&#x25C6;</div>

    $flash_html

    <section class="agenda-section">
        <h3 class="section-title">Bloquear horário</h3>
        <p class="section-sub">Defina um período de indisponibilidade em seu fuso horário ($tz).</p>
        <form method="post" class="block-form">
            <input type="hidden" name="action" value="block">
            <input type="hidden" name="timezone" value="$tz">
            <div class="field">
                <label for="block_date">Data</label>
                <input type="date" id="block_date" name="block_date" required>
            </div>
            <div class="field">
                <label for="block_start">Início</label>
                <input type="time" id="block_start" name="block_start" required>
            </div>
            <div class="field">
                <label for="block_end">Fim</label>
                <input type="time" id="block_end" name="block_end" required>
            </div>
            <button type="submit" class="btn">Bloquear</button>
        </form>
    </section>

    <div class="divider">&#x25C6;</div>

    <section class="agenda-section blocks-section" data-block-count="$blocks_count">
        <h3 class="section-title">Horários bloqueados</h3>
        $blocks_html
    </section>

    <a href="/" class="footer-link">Voltar ao site</a>
</div>
HTML;

echo render_html('Agenda', $body);