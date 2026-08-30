<?php
/**
 * Onboarding portal — <host>/onboard/<token>
 * Self-serve page where artists scan QR and connect via WhatsApp.
 *
 * Talks to the Artist Onboard Webhook (n8n, path /onboard-api) with an
 * `action` field: validate | status | consume. Standalone sdr-admin bundle.
 */

declare(strict_types=1);

// --- Helpers ---

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
            'timeout' => 15,
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
            max-width: 420px; width: 100%;
            text-align: center;
        }
        .logo { width: 64px; height: auto; margin-bottom: 24px; }
        h1 {
            font-family: 'Cormorant Garamond', serif;
            font-size: 2rem; font-weight: 600;
            color: rgb(242, 237, 228); margin-bottom: 12px;
        }
        h2 {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.25rem; font-weight: 400;
            color: rgb(160, 154, 142); margin-bottom: 32px; line-height: 1.5;
        }
        .qr-container {
            background: #ffffff; border-radius: 12px;
            padding: 20px; margin: 24px 0; display: inline-block;
        }
        .qr-container img { display: block; width: 220px; height: 220px; }
        .qr-expiry { font-size: 0.825rem; color: rgb(160, 154, 142); margin-top: 12px; }
        .divider { color: #D4B04C; font-size: 1rem; margin: 24px 0; letter-spacing: 8px; }
        .instructions {
            text-align: left; font-size: 0.9rem;
            color: rgb(160, 154, 142); line-height: 1.8; margin: 24px 0; padding: 0 8px;
        }
        .instructions ol { padding-left: 20px; }
        .instructions li { margin-bottom: 10px; }
        .status-badge {
            display: inline-block; padding: 8px 20px; border-radius: 20px;
            font-size: 0.85rem; font-weight: 600; letter-spacing: 0.5px; margin: 16px 0;
        }
        .status-waiting { background: #1a1a1a; color: #D4B04C; border: 1px solid #D4B04C; }
        .status-success { background: #0a2a0a; color: #4ade80; border: 1px solid #4ade80; }
        .status-error { background: #2a0a0a; color: #f87171; border: 1px solid #f87171; }
        .btn {
            display: inline-block; background: #D4B04C; color: #0A0A0A;
            padding: 14px 36px; border-radius: 8px; text-decoration: none;
            font-weight: 700; font-size: 0.9rem; letter-spacing: 1px;
            text-transform: uppercase; margin-top: 16px;
        }
        .btn:hover { opacity: 0.9; }
        .hidden { display: none !important; }
        .spinner {
            display: inline-block; width: 20px; height: 20px;
            border: 2px solid #D4B04C; border-top-color: transparent;
            border-radius: 50%; animation: spin 0.8s linear infinite;
            vertical-align: middle; margin-right: 8px;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .icon-large { font-size: 3rem; margin-bottom: 16px; }
    </style>
    $extra_head
</head>
<body>$body</body>
</html>
HTML;
}

// ================================================================
// Routes
// ================================================================

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

// Route: /onboard/sucesso
if ($path === '/onboard/sucesso' || str_ends_with($path, '/onboard/sucesso')) {
    $body = <<<HTML
    <div class="card">
        <div class="icon-large">&#x2705;</div>
        <h1>Conectado com sucesso!</h1>
        <h2>Sua conta WhatsApp Business foi conectada. A Beatriz já está pronta para atender seus clientes.</h2>
        <p style="color: rgb(160,154,142); font-size: 0.9rem; margin: 16px 0;">
            Agora abra seu WhatsApp Business. A Beatriz vai te enviar uma mensagem para guiar a configuração do seu perfil.
        </p>
        <a href="/agenda/" class="btn">Gerenciar minha agenda</a>
    </div>
HTML;
    echo render_html('Conectado', $body);
    exit;
}

// Extract token from /onboard/<token>
$token = null;
if (preg_match('#^/onboard/([a-z0-9]{8,64})$#i', $path, $m)) {
    $token = $m[1];
}

// Route: missing/invalid
if ($token === null || $token === '') {
    $body = <<<HTML
    <div class="card">
        <div class="icon-large">&#x26A0;</div>
        <h1>Link inválido</h1>
        <h2>O link de onboarding não foi encontrado. Verifique a URL ou solicite um novo link ao parceiro VAIF.</h2>
        <a href="/" class="btn">Voltar ao site</a>
    </div>
HTML;
    echo render_html('Link Inválido', $body);
    exit;
}

// Validate token against the n8n onboard webhook
$webhook_url = getenv('N8N_ONBOARD_WEBHOOK_URL') ?: '';
if ($webhook_url === '') {
    $body = <<<HTML
    <div class="card">
        <div class="icon-large">&#x26A0;</div>
        <h1>Em manutenção</h1>
        <h2>O sistema de onboarding está temporariamente indisponível. Tente novamente em alguns minutos.</h2>
        <a href="/" class="btn">Voltar ao site</a>
    </div>
HTML;
    echo render_html('Indisponível', $body);
    exit;
}

$result = call_n8n($webhook_url, ['token' => $token, 'action' => 'validate']);

if ($result === null || !($result['valid'] ?? false)) {
    $msg = htmlspecialchars($result['error'] ?? 'Este link expirou ou já foi utilizado. Solicite um novo link de onboarding ao parceiro VAIF.');
    $body = <<<HTML
    <div class="card">
        <div class="icon-large">&#x26A0;</div>
        <h1>Link inválido ou expirado</h1>
        <h2>$msg</h2>
        <a href="/" class="btn">Voltar ao site</a>
    </div>
HTML;
    echo render_html('Link Expirado', $body);
    exit;
}

// --- Token is valid — render onboarding page ---
$artist_name = htmlspecialchars($result['artist_name'] ?? 'Artista');
$webhook_url_js = addcslashes($webhook_url, "'\\");

// QR image (base64 PNG string or empty)
$qr_image = $result['qr_image'] ?? '';
$qr_html = '';
if ($qr_image !== '') {
    $src = str_starts_with($qr_image, 'data:') ? $qr_image : "data:image/png;base64,$qr_image";
    $qr_html = "<img src=\"$src\" alt=\"QR Code WhatsApp\" id=\"qr-image\">";
} else {
    $qr_html = '<p style="color:#666;padding:60px 40px;">QR Code indisponível<br>Recarregue a página.</p>';
}

// JS config (encode for safe embedding in <script>)
$js_token  = json_encode(['token' => $token]);

$body = <<<HTML
<div class="card">
    <h1>$artist_name, bem-vindo(a)</h1>
    <h2>Escaneie o QR code com a câmera do seu WhatsApp Business para conectar sua conta e ativar a Beatriz.</h2>

    <div class="qr-container" id="qr-container">
        $qr_html
        <div class="qr-expiry" id="qr-expiry-text">O QR será atualizado automaticamente.</div>
    </div>

    <div class="divider">&#x25C6;</div>

    <div class="instructions">
        <ol>
            <li>Abra o <strong>WhatsApp Business</strong> no seu celular</li>
            <li>Vá em <strong>Aparelhos Conectados</strong></li>
            <li>Toque em <strong>Conectar um Aparelho</strong></li>
            <li>Escaneie o QR code acima</li>
            <li>Aguarde a confirmação</li>
        </ol>
    </div>

    <div id="status-area">
        <div class="status-badge status-waiting" id="status-badge">
            <span class="spinner"></span> Aguardando conexão...
        </div>
    </div>
</div>

<script>
(function() {
    var token = $js_token;
    var webhookUrl = '$webhook_url_js';
    var consumed = false;
    var pollCount = 0;

    function setConnected() {
        var badge = document.getElementById('status-badge');
        if (badge) {
            badge.className = 'status-badge status-success';
            badge.innerHTML = '&#x2713; Conectado!';
        }
    }

    function setError(msg) {
        var badge = document.getElementById('status-badge');
        if (badge) {
            badge.className = 'status-badge status-error';
            badge.innerHTML = msg;
        }
    }

    function post(payload) {
        return fetch(webhookUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        }).then(function(r) { return r.json(); });
    }

    function checkStatus() {
        if (consumed) return;
        pollCount++;
        if (pollCount > 120) {
            setError('Tempo limite. Recarregue a página para tentar novamente.');
            return;
        }

        post(Object.assign({ action: 'status' }, token))
        .then(function(data) {
            if (data && data.connected) {
                consumed = true;
                setConnected();
                post(Object.assign({ action: 'consume' }, token));
                setTimeout(function() {
                    window.location.href = '/onboard/sucesso';
                }, 2000);
            }
        })
        .catch(function() {});
    }

    function refreshQr() {
        post(Object.assign({ action: 'status', refreshQr: true }, token))
        .then(function(data) {
            if (data && data.qr_image) {
                var img = document.getElementById('qr-image');
                if (img) img.src = 'data:image/png;base64,' + data.qr_image;
            }
        })
        .catch(function() {});
    }

    setInterval(checkStatus, 5000);
    checkStatus();

    if (document.getElementById('qr-image')) {
        setInterval(refreshQr, 55000); // Refresh QR every 55s
    }
})();
</script>
HTML;

echo render_html('Onboarding', $body);