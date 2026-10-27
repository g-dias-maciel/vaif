<?php
/**
 * Promotions admin portal — vaif.com.br/promo/<admin_token>
 * VAIF-partner seam for the Offers module: create/activate/pause/end an
 * artist's promotion (one active promotion per artist). Auth: the VAIF admin
 * token (VAIF_ADMIN_TOKEN env), sent as the URL path segment.
 *
 * Backed by the "Artist Promotions Webhook" (path /promotions).
 */

declare(strict_types=1);

$admin_token = getenv('VAIF_ADMIN_TOKEN') ?: '';
$webhook_url = getenv('N8N_PROMOTIONS_WEBHOOK_URL') ?: '';

function promo_call(string $url, array $data): ?array
{
    if ($url === '') {
        return null;
    }
    $ctx = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\n",
            'content' => json_encode($data),
            'timeout' => 10,
        ],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        return null;
    }
    return json_decode($body, true) ?: null;
}

function promo_render(string $title, string $body, string $extra_head = ''): string
{
    return <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>$title — VAIF</title>
    <link rel="icon" type="image/x-icon" href="/img/favicon.ico">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;600;700&family=Montserrat:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            background: radial-gradient(ellipse at top, #1a1a1a 0%, #0A0A0A 70%);
            color: rgb(242, 237, 228);
            font-family: 'Montserrat', sans-serif;
            min-height: 100vh; padding: 24px 16px;
        }
        .wrap { max-width: 880px; margin: 0 auto; }
        .card {
            background: #121212; border: 1px solid #222222; border-radius: 16px;
            padding: 32px; margin-bottom: 20px;
        }
        h1 { font-family: 'Cormorant Garamond', serif; font-size: 1.8rem; font-weight: 600; margin-bottom: 8px; }
        h2 { font-family: 'Cormorant Garamond', serif; font-size: 1.1rem; font-weight: 400; color: rgb(160,154,142); margin-bottom: 20px; }
        .divider { color: #D4B04C; font-size: 1rem; margin: 20px 0; letter-spacing: 8px; }
        .section-title { font-family: 'Cormorant Garamond', serif; font-size: 1.05rem; font-weight: 600; text-transform: uppercase; letter-spacing: 1.5px; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
        th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid #222; }
        th { color: rgb(160,154,142); text-transform: uppercase; font-size: 0.7rem; letter-spacing: 1px; }
        .badge { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 0.7rem; font-weight: 600; }
        .b-active { background: #0a2a0a; color: #4ade80; border: 1px solid #4ade80; }
        .b-draft { background: #1a1a1a; color: rgb(160,154,142); border: 1px solid #333; }
        .b-paused { background: #1a1a1a; color: #D4B04C; border: 1px solid #D4B04C; }
        .b-ended { background: #2a0a0a; color: #f87171; border: 1px solid #f87171; }
        .btn {
            display: inline-block; background: #D4B04C; color: #0A0A0A; padding: 8px 14px;
            border-radius: 8px; border: none; cursor: pointer; text-decoration: none;
            font-family: 'Montserrat', sans-serif; font-weight: 700; font-size: 0.72rem;
            letter-spacing: 1px; text-transform: uppercase; margin: 2px;
        }
        .btn:hover { opacity: 0.9; }
        .btn--ghost { background: transparent; color: rgb(242,237,228); border: 1px solid #D4B04C; }
        .btn--danger { background: transparent; color: #f87171; border: 1px solid #f87171; }
        .field { display: flex; flex-direction: column; gap: 6px; margin-bottom: 14px; }
        .field label { font-size: 0.72rem; letter-spacing: 0.5px; text-transform: uppercase; color: rgb(160,154,142); }
        .field input, .field select {
            background: #0A0A0A; border: 1px solid #222; border-radius: 8px; color: rgb(242,237,228);
            padding: 10px 12px; font-family: 'Montserrat', sans-serif; font-size: 0.9rem;
        }
        .field input:focus, .field select:focus { outline: none; border-color: #D4B04C; }
        .status-badge { display: block; padding: 10px 16px; border-radius: 8px; font-size: 0.85rem; font-weight: 600; margin-bottom: 16px; }
        .status-success { background: #0a2a0a; color: #4ade80; border: 1px solid #4ade80; }
        .status-error { background: #2a0a0a; color: #f87171; border: 1px solid #f87171; }
        .inline-form { display: inline; }
        .muted { color: rgb(160,154,142); font-size: 0.8rem; }
    </style>
    $extra_head
</head>
<body><div class="wrap">$body</div></body>
</html>
HTML;
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$token = null;
if (preg_match('#^/promo(?:/([a-zA-Z0-9_-]{8,128}))?$#i', $path, $m)) {
    $token = $m[1] ?? null;
}

if ($admin_token === '' || $webhook_url === '') {
    echo promo_render('Indisponível', '<div class="card"><div class="status-badge status-error">Painel de promoções não configurado.</div></div>');
    exit;
}

if ($token === null || !hash_equals($admin_token, $token)) {
    echo promo_render('Acesso negado', '<div class="card"><div class="status-badge status-error">Token de admin inválido.</div><a href="/" class="btn">Voltar</a></div>');
    exit;
}

$flash = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';
    $payload = ['admin_token' => $admin_token, 'action' => $action];

    if ($action === 'create') {
        $payload['artist_id']  = (string) ($_POST['artist_id'] ?? '');
        $payload['name']       = (string) ($_POST['name'] ?? 'Promoção');
        $payload['multiplier'] = (float) ($_POST['multiplier'] ?? 2.0);
        $payload['audience']   = (string) ($_POST['audience'] ?? 'all');
    } elseif ($action === 'activate' || $action === 'pause' || $action === 'end') {
        $payload['promotion_id'] = (string) ($_POST['promotion_id'] ?? '');
    }

    $result = promo_call($webhook_url, $payload);
    if ($result !== null && ($result['success'] ?? false)) {
        $flash = ['type' => 'success', 'text' => 'Ação "' . $action . '" realizada com sucesso.'];
    } else {
        $flash = ['type' => 'error', 'text' => htmlspecialchars($result['message'] ?? 'Falha na ação.')];
    }
}

$artists_data = promo_call($webhook_url, ['admin_token' => $admin_token, 'action' => 'list_artists']);
$promos_data  = promo_call($webhook_url, ['admin_token' => $admin_token, 'action' => 'list_promotions']);

$artists  = is_array($artists_data['artists'] ?? null) ? $artists_data['artists'] : [];
$promos   = is_array($promos_data['promotions'] ?? null) ? $promos_data['promotions'] : [];

$flash_html = '';
if ($flash !== null) {
    $cls = $flash['type'] === 'success' ? 'status-success' : 'status-error';
    $flash_html = '<div class="status-badge ' . $cls . '">' . $flash['text'] . '</div>';
}

// Create form: artist dropdown
$artist_options = '';
foreach ($artists as $a) {
    $aid = htmlspecialchars((string) ($a['id'] ?? ''));
    $aname = htmlspecialchars((string) ($a['nome'] ?? 'Artista'));
    $apromo = $a['active_promotion'] ?? null;
    $note = $apromo ? ' (ativo: ' . htmlspecialchars((string) $apromo) . ')' : '';
    $artist_options .= '<option value="' . $aid . '">' . $aname . $note . '</option>';
}

$badge_map = [
    'active' => 'b-active', 'draft' => 'b-draft', 'paused' => 'b-paused', 'ended' => 'b-ended',
];
$promos_html = '';
if (count($promos) === 0) {
    $promos_html = '<p class="muted">Nenhuma promoção criada ainda.</p>';
} else {
    $rows = '';
    foreach ($promos as $p) {
        $pid      = htmlspecialchars((string) ($p['id'] ?? ''));
        $aname    = htmlspecialchars((string) ($p['artist_name'] ?? ''));
        $pname    = htmlspecialchars((string) ($p['name'] ?? ''));
        $mult     = htmlspecialchars((string) ($p['multiplier'] ?? ''));
        $status   = (string) ($p['status'] ?? 'draft');
        $badge    = $badge_map[$status] ?? 'b-draft';
        $actions  = '';
        if ($status === 'draft' || $status === 'paused') {
            $actions .= '<form method="post" class="inline-form"><input type="hidden" name="action" value="activate"><input type="hidden" name="promotion_id" value="' . $pid . '"><button class="btn">Ativar</button></form>';
        }
        if ($status === 'active') {
            $actions .= '<form method="post" class="inline-form"><input type="hidden" name="action" value="pause"><input type="hidden" name="promotion_id" value="' . $pid . '"><button class="btn btn--ghost">Pausar</button></form>';
        }
        if ($status === 'active' || $status === 'paused') {
            $actions .= '<form method="post" class="inline-form"><input type="hidden" name="action" value="end"><input type="hidden" name="promotion_id" value="' . $pid . '"><button class="btn btn--danger">Encerrar</button></form>';
        }
        $rows .= '<tr><td>' . $aname . '</td><td>' . $pname . '</td><td>' . $mult . 'x</td><td><span class="badge ' . $badge . '">' . $status . '</span></td><td>' . $actions . '</td></tr>';
    }
    $promos_html = '<table><thead><tr><th>Artista</th><th>Nome</th><th>Mult.</th><th>Status</th><th>Ações</th></tr></thead><tbody>' . $rows . '</tbody></table>';
}

$body = <<<HTML
<div class="card">
    <h1>Promoções — VAIF</h1>
    <h2>Crie e controle a promoção ativa de cada artista. Uma promoção ativa por artista; o preço é o multiplicador sobre a tabela, congelado no momento da ativação.</h2>
    $flash_html

    <div class="divider">&#x25C6;</div>

    <div class="section-title">Criar promoção</div>
    <form method="post">
        <input type="hidden" name="action" value="create">
        <div class="field">
            <label for="artist_id">Artista</label>
            <select id="artist_id" name="artist_id" required>$artist_options</select>
        </div>
        <div class="field">
            <label for="name">Nome da promoção</label>
            <input type="text" id="name" name="name" value="Leve 2 pelo valor de 2x" required>
        </div>
        <div class="field">
            <label for="multiplier">Multiplicador de preço (2 = o dobro da tabela)</label>
            <input type="number" step="0.05" min="1" id="multiplier" name="multiplier" value="2.0" required>
        </div>
        <div class="field">
            <label for="audience">Público</label>
            <select id="audience" name="audience">
                <option value="all">Todos</option>
                <option value="new">Somente novos</option>
                <option value="returning">Somente recorrentes</option>
            </select>
        </div>
        <button type="submit" class="btn">Criar</button>
        <p class="muted">Depois de criar, clique em "Ativar" na lista abaixo para congelar os preços e liberar a promoção para a Beatriz.</p>
    </form>
</div>

<div class="card">
    <div class="section-title">Promoções existentes</div>
    $promos_html
</div>
HTML;

echo promo_render('Promoções', $body);
