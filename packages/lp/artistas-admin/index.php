<?php

/**
 * Artist admin panel — /artistas-admin/<token>[/<id>]
 *
 * Token is the ARTISTS_ADMIN_TOKEN env var (falls back to API_CREATE_KEY).
 * Lists DB-backed artists, lets you review/edit them (shared intake form in
 * update mode), preview drafts, publish/unpublish, and delete.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/artists/store.php';
require_once __DIR__ . '/../lib/artists/intake-form.php';

function admin_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function admin_render(string $title, string $body, bool $withFormCss = false): void
{
    $formCss = $withFormCss ? '<link rel="stylesheet" href="/novo-artista/form.css">' : '';
    echo <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>$title — VAIF</title>
    <link rel="icon" href="/img/favicon/favicon.ico" sizes="any" type="image/x-icon">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;600;700&family=Montserrat:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/artistas-admin/admin.css">
    $formCss
</head>
<body>$body</body>
</html>
HTML;
}

// ================================================================
// Routing + auth
// ================================================================

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

$token = '';
$id = 0;
if (preg_match('#^/artistas-admin/([A-Za-z0-9_-]+)(?:/(\d+))?/?$#', $path, $m)) {
    $token = $m[1];
    $id = isset($m[2]) ? (int) $m[2] : 0;
}

$expectedToken = artist_admin_token();

if ($token === '' || $expectedToken === '' || !hash_equals($expectedToken, $token)) {
    $body = <<<HTML
    <div class="card-center">
        <h1>Link inválido</h1>
        <p>O painel de artistas não foi encontrado. Verifique a URL ou solicite o link ao administrador.</p>
        <a href="/" class="btn">Voltar ao site</a>
    </div>
HTML;
    admin_render('Link Inválido', $body);
    exit;
}

$pdo = artist_db();
if ($pdo === null) {
    $body = <<<HTML
    <div class="card-center">
        <h1>Em manutenção</h1>
        <p>O painel está temporariamente indisponível. Tente novamente em alguns minutos.</p>
        <a href="/" class="btn">Voltar ao site</a>
    </div>
HTML;
    admin_render('Indisponível', $body);
    exit;
}

$baseUrl = '/artistas-admin/' . rawurlencode($token);

// ================================================================
// POST actions for invite tokens (list view) — PRG
// ================================================================

if ($id === 0 && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'generate_token') {
        $label = trim((string) ($_POST['token_label'] ?? ''));
        $newToken = intake_token_create($pdo, $label);
        header('Location: ' . $baseUrl . '?new_token=' . rawurlencode($newToken));
        exit;
    }
    if ($action === 'revoke_token') {
        $tid = (int) ($_POST['token_id'] ?? 0);
        if ($tid > 0) {
            intake_token_revoke($pdo, $tid);
        }
        header('Location: ' . $baseUrl);
        exit;
    }
}

// ================================================================
// POST actions (publish / unpublish / delete) — PRG
// ================================================================

if ($id > 0 && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $row = artist_db_load_by_id($pdo, $id);

    if ($row === null) {
        $body = '<div class="card-center"><h1>Não encontrado</h1><p>Artista não existe.</p><a href="' . admin_h($baseUrl) . '" class="btn">Voltar</a></div>';
        admin_render('Não Encontrado', $body);
        exit;
    }

    if ($action === 'publish') {
        artist_db_set_status($pdo, $id, 'published');
    } elseif ($action === 'unpublish') {
        artist_db_set_status($pdo, $id, 'draft');
    } elseif ($action === 'delete') {
        artist_db_delete($pdo, $id);
        artist_delete_media_dir((string) $row['slug']);
        header('Location: ' . $baseUrl);
        exit;
    }

    header('Location: ' . $baseUrl . '/' . $id);
    exit;
}

// ================================================================
// Edit view
// ================================================================

if ($id > 0) {
    $row = artist_db_load_by_id($pdo, $id);

    if ($row === null) {
        $body = '<div class="card-center"><h1>Não encontrado</h1><p>Artista não existe.</p><a href="' . admin_h($baseUrl) . '" class="btn">Voltar</a></div>';
        admin_render('Não Encontrado', $body);
        exit;
    }

    $data = $row['data'];
    $status = (string) $row['status'];
    $slug = (string) $row['slug'];
    $displayName = (string) ($data['display_name'] ?? $row['display_name']);
    $previewToken = (string) $row['preview_token'];
    $previewUrl = '/artists/' . $slug . '?preview=' . $previewToken;
    $publicUrl = '/artists/' . $slug;
    $badge = $status === 'published'
        ? '<span class="badge badge--published">Publicado</span>'
        : '<span class="badge badge--draft">Rascunho</span>';

    $publishBtn = $status === 'published'
        ? '<form method="post" class="inline-form"><input type="hidden" name="action" value="unpublish"><button type="submit" class="btn btn--ghost btn--small">Despublicar</button></form>'
        : '<form method="post" class="inline-form"><input type="hidden" name="action" value="publish"><button type="submit" class="btn btn--small">Publicar</button></form>';

    $deleteBtn = '<form method="post" class="inline-form" onsubmit="return confirm(\'Excluir este artista? Esta ação não pode ser desfeita.\');"><input type="hidden" name="action" value="delete"><button type="submit" class="btn btn--danger btn--small">Excluir</button></form>';

    $submitter = '';
    if (!empty($row['submitter_name']) || !empty($row['submitter_contact'])) {
        $submitter = 'Enviado por: ' . admin_h((string) $row['submitter_name']) . ' ' . admin_h((string) $row['submitter_contact']);
    }

    $metaSection = <<<HTML
    <section class="admin-section">
        <h2>{$displayName} {$badge}</h2>
        <p class="meta-line">Slug: <code>{$slug}</code></p>
        <p class="meta-line">Prévia: <code>{$previewUrl}</code></p>
        <p class="meta-line">Público: <code>{$publicUrl}</code></p>
        {$submitter}
        <div class="admin-actions">
            <a href="{$previewUrl}" target="_blank" rel="noopener" class="btn btn--ghost btn--small">Ver prévia</a>
            {$publishBtn}
            {$deleteBtn}
        </div>
    </section>
HTML;

    $formHtml = render_artist_intake_form($data, 'edit', $id, $token);

    $body = '<div class="admin-shell">'
        . '<a href="' . admin_h($baseUrl) . '" class="admin-back">&larr; Voltar para a lista</a>'
        . $metaSection
        . $formHtml
        . '<script src="/novo-artista/form.js" defer></script>'
        . '</div>';

    admin_render('Editar — ' . admin_h($displayName), $body, true);
    exit;
}

// ================================================================
// List view
// ================================================================

$artists = artist_db_list($pdo);

$cards = '';
if ($artists === []) {
    $cards = '<div class="empty-state"><p>Nenhum artista cadastrado ainda. Gere um link de cadastro abaixo e envie para o artista.</p></div>';
} else {
    foreach ($artists as $a) {
        $data = $a['data'];
        $name = admin_h((string) ($data['display_name'] ?? $a['display_name']));
        $slug = admin_h((string) $a['slug']);
        $status = (string) $a['status'];
        $idA = (int) $a['id'];
        $previewUrl = '/artists/' . admin_h((string) $a['slug']) . '?preview=' . admin_h((string) $a['preview_token']);
        $editUrl = $baseUrl . '/' . $idA;
        $badge = $status === 'published'
            ? '<span class="badge badge--published">Publicado</span>'
            : '<span class="badge badge--draft">Rascunho</span>';

        $publishBtn = $status === 'published'
            ? '<form method="post" class="inline-form"><input type="hidden" name="action" value="unpublish"><button type="submit" class="btn btn--ghost btn--small">Despublicar</button></form>'
            : '<form method="post" class="inline-form"><input type="hidden" name="action" value="publish"><button type="submit" class="btn btn--small">Publicar</button></form>';

        $cards .= <<<HTML
        <div class="artist-card">
            <div class="artist-card-main">
                <div class="artist-card-name">{$name} {$badge}</div>
                <div class="artist-card-slug">/artists/{$slug}</div>
            </div>
            <div class="artist-card-actions">
                <a href="{$previewUrl}" target="_blank" rel="noopener" class="btn btn--ghost btn--small">Prévia</a>
                <a href="{$editUrl}" class="btn btn--small">Editar</a>
                {$publishBtn}
            </div>
        </div>
HTML;
    }
}

// ---- Invite tokens ----
$tokens = intake_token_list($pdo);
$newToken = (string) ($_GET['new_token'] ?? '');

$newTokenBox = '';
if ($newToken !== '') {
    $link = 'https://vaif.com.br/novo-artista/' . $newToken;
    $newTokenBox = <<<HTML
    <div class="admin-section" style="border-color:var(--gold);">
        <h2>Novo link de cadastro</h2>
        <p class="meta-line">Envie este link para o artista. Ele é de <strong>uso único</strong> e expira após o envio.</p>
        <div class="token-link">
            <code>{$link}</code>
            <button type="button" class="btn btn--ghost btn--small" data-copy="{$link}">Copiar</button>
        </div>
    </div>
HTML;
}

$tokenRows = '';
if ($tokens === []) {
    $tokenRows = '<p class="meta-line">Nenhum link gerado ainda.</p>';
} else {
    foreach ($tokens as $t) {
        $tid = (int) $t['id'];
        $tk = (string) $t['token'];
        $status = (string) $t['status'];
        $label = admin_h((string) ($t['label'] ?? ''));
        $usedSlug = admin_h((string) ($t['used_by_slug'] ?? ''));
        $link = 'https://vaif.com.br/novo-artista/' . $tk;

        if ($status === 'used') {
            $badge = '<span class="badge badge--published">Usado</span>';
        } elseif ($status === 'revoked') {
            $badge = '<span class="badge badge--muted">Revogado</span>';
        } else {
            $badge = '<span class="badge badge--draft">Ativo</span>';
        }

        $revokeBtn = $status === 'active'
            ? '<form method="post" class="inline-form"><input type="hidden" name="action" value="revoke_token"><input type="hidden" name="token_id" value="' . $tid . '"><button type="submit" class="btn btn--danger btn--small">Revogar</button></form>'
            : '';

        $labelText = $label !== '' ? $label : 'Sem rótulo';
        $tokenHint = substr($tk, 0, 10) . '…';
        if ($usedSlug !== '') {
            $tokenHint .= ' &rarr; /artists/' . $usedSlug;
        }

        $tokenRows .= <<<HTML
        <div class="artist-card">
            <div class="artist-card-main">
                <div class="artist-card-name">{$labelText} {$badge}</div>
                <div class="artist-card-slug">{$tokenHint}</div>
            </div>
            <div class="artist-card-actions">
                <button type="button" class="btn btn--ghost btn--small" data-copy="{$link}">Copiar link</button>
                {$revokeBtn}
            </div>
        </div>
HTML;
    }
}

$tokenSection = <<<HTML
    <section class="admin-section">
        <h2>Links de cadastro</h2>
        <form method="post" class="admin-inline-form">
            <input type="hidden" name="action" value="generate_token">
            <input type="text" name="token_label" placeholder="Rótulo (ex.: nome do artista)">
            <button type="submit" class="btn">Gerar link de cadastro</button>
        </form>
        <div style="margin-top:16px;" class="admin-list">{$tokenRows}</div>
    </section>
HTML;

$body = <<<HTML
<div class="admin-shell">
    <div class="admin-topbar">
        <h1 class="admin-title">Artistas <small>Painel de revisão</small></h1>
    </div>
    {$newTokenBox}
    {$tokenSection}
    <div class="admin-list">{$cards}</div>
    <script>
    document.querySelectorAll('[data-copy]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var text = btn.getAttribute('data-copy');
            function done() { btn.textContent = 'Copiado!'; setTimeout(function(){ btn.textContent = 'Copiar'; }, 1500); }
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(done, function () { fallback(text); done(); });
            } else { fallback(text); done(); }
            function fallback(t) {
                var ta = document.createElement('textarea');
                ta.value = t; document.body.appendChild(ta); ta.select();
                try { document.execCommand('copy'); } catch (e) {}
                document.body.removeChild(ta);
            }
        });
    });
    </script>
</div>
HTML;

admin_render('Artistas', $body);
