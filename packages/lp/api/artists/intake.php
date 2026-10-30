<?php

/**
 * Artist intake endpoint — /api/artists/intake.php
 *
 * Handles the multipart submission from the artist intake form (create mode)
 * and the admin edit form (update mode). On create it persists a draft artist
 * row + uploaded media and returns a preview link. On update it requires the
 * admin token and overwrites the stored data (keeping existing media files that
 * were not replaced).
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: POST');

require_once __DIR__ . '/../../lib/artists/store.php';

/**
 * Normalize a $_FILES array for a repeated file input (name="x[]") into a list
 * of single-file entries (or null for empty slots). Returns [] when absent.
 */
function intake_files(string $key): array
{
    $f = $_FILES[$key] ?? null;
    if (!is_array($f)) {
        return [];
    }
    $names = isset($f['name']) && is_array($f['name']) ? $f['name'] : [];
    $count = count($names);
    $out = [];
    for ($i = 0; $i < $count; $i++) {
        $error = isset($f['error']) && is_array($f['error']) ? (int) ($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_NO_FILE) {
            $out[] = null;
            continue;
        }
        $out[] = [
            'name'     => isset($f['name']) && is_array($f['name']) ? (string) ($f['name'][$i] ?? '') : '',
            'type'     => isset($f['type']) && is_array($f['type']) ? (string) ($f['type'][$i] ?? '') : '',
            'tmp_name' => isset($f['tmp_name']) && is_array($f['tmp_name']) ? (string) ($f['tmp_name'][$i] ?? '') : '',
            'error'    => $error,
            'size'     => isset($f['size']) && is_array($f['size']) ? (int) ($f['size'][$i] ?? 0) : 0,
        ];
    }
    return $out;
}

/** Fetch a single-file entry (non-repeated input). */
function intake_file(string $key): array
{
    $f = $_FILES[$key] ?? null;
    if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [];
    }
    return $f;
}

function intake_str(string $key): string
{
    $v = $_POST[$key] ?? '';
    return is_string($v) ? trim($v) : '';
}

function intake_list(string $key): array
{
    $v = $_POST[$key] ?? [];
    return is_array($v) ? array_map(fn($x) => trim((string) $x), $v) : [];
}

function respond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(int $status, string $error): never
{
    respond($status, ['success' => false, 'error' => $error]);
}

// ================================================================
// Mode + auth
// ================================================================

$mode = intake_str('mode') === 'update' ? 'update' : 'create';

$pdo = artist_db();
if ($pdo === null) {
    fail(503, 'Banco de dados indisponível no momento. Tente novamente em instantes.');
}

$id = 0;
$existing = null;
$adminToken = '';

if ($mode === 'update') {
    $adminToken = (string) ($_SERVER['HTTP_X_ADMIN_TOKEN'] ?? $_POST['admin_token'] ?? '');
    $expected = artist_admin_token();
    if ($expected === '' || $adminToken === '' || !hash_equals($expected, $adminToken)) {
        fail(401, 'Token de administração inválido.');
    }
    $id = (int) intake_str('id');
    if ($id <= 0) {
        fail(422, 'ID do artista ausente.');
    }
    $existing = artist_db_load_by_id($pdo, $id);
    if ($existing === null) {
        fail(404, 'Artista não encontrado.');
    }
}

// ================================================================
// Invite token (create mode only)
// ================================================================

$intakeToken = '';
if ($mode === 'create') {
    $intakeToken = intake_str('intake_token');
    if ($intakeToken === '' || !intake_token_is_active($pdo, $intakeToken)) {
        fail(403, 'Link de cadastro inválido ou já utilizado.');
    }
}

// ================================================================
// Slug
// ================================================================

$slug = $mode === 'update' ? (string) $existing['slug'] : '';

if ($mode === 'create') {
    $displayName = intake_str('display_name');
    $whatsapp = preg_replace('/[^0-9]/', '', intake_str('whatsapp_number'));

    if ($displayName === '') {
        fail(422, 'Informe o nome do artista.');
    }
    if ($whatsapp === '') {
        fail(422, 'Informe o WhatsApp do artista (com DDI).');
    }
    $slug = artist_unique_slug($pdo, $displayName);
}

// ================================================================
// Build the artist config array from form fields
// ================================================================

$data = $mode === 'update' ? $existing['data'] : [];

// The template requires `slug` inside the config array (config files carry it).
$data['slug'] = $slug;

$set = function (string $key, string $value) use (&$data): void {
    if ($value !== '') {
        $data[$key] = $value;
    } else {
        unset($data[$key]);
    }
};

$set('display_name', intake_str('display_name'));
$set('whatsapp_number', preg_replace('/[^0-9]/', '', intake_str('whatsapp_number')));
$set('instagram_handle', ltrim(intake_str('instagram_handle'), '@'));
$set('style', intake_str('style'));
$set('price_range', intake_str('price_range'));
$set('hero_headline', intake_str('hero_headline'));
$set('hero_subheadline', intake_str('hero_subheadline'));
$set('cta_text', intake_str('cta_text') !== '' ? intake_str('cta_text') : '');
$set('anos_de_experiencia', intake_str('anos_de_experiencia'));

// Bio — sanitized to safe HTML (see store.php).
$bio = artist_sanitize_bio((string) ($_POST['bio'] ?? ''));
if ($bio !== '') {
    $data['bio'] = $bio;
} else {
    unset($data['bio']);
}

// Specialty / before-after gating.
$hasCoberturas = ($_POST['has_coberturas'] ?? '') === '1';
if ($hasCoberturas) {
    $data['specialty'] = 'coberturas';
} else {
    unset($data['specialty']);
    unset($data['before_after']);
}

// ---- Stats ----
$statValues = intake_list('stat_value');
$statLabels = intake_list('stat_label');
$stats = [];
foreach ($statValues as $i => $v) {
    if ($v === '') {
        continue;
    }
    $stats[] = ['value' => $v, 'label' => $statLabels[$i] ?? ''];
}
if ($stats !== []) {
    $data['stats'] = $stats;
} else {
    unset($data['stats']);
}

// ---- Testimonials ----
$tNames = intake_list('testimonial_name');
$tMetas = intake_list('testimonial_meta');
$tTexts = intake_list('testimonial_text');
$tRatings = intake_list('testimonial_rating');
$testimonials = [];
foreach ($tNames as $i => $name) {
    $text = $tTexts[$i] ?? '';
    if ($name === '' && $text === '') {
        continue;
    }
    $testimonials[] = [
        'name'   => $name !== '' ? $name : 'Cliente',
        'meta'   => $tMetas[$i] ?? 'Cliente',
        'text'   => $text,
        'rating' => max(1, min(5, (int) ($tRatings[$i] ?? 5))),
    ];
}
if ($testimonials !== []) {
    $data['testimonials'] = $testimonials;
} else {
    unset($data['testimonials']);
}

// ---- FAQ ----
$faqQuestions = intake_list('faq_question');
$faqAnswers = intake_list('faq_answer');
$faqs = [];
foreach ($faqQuestions as $i => $q) {
    $a = $faqAnswers[$i] ?? '';
    if ($q === '' || $a === '') {
        continue;
    }
    $faqs[] = ['question' => $q, 'answer' => $a];
}
if ($faqs !== []) {
    $data['faq'] = $faqs;
} else {
    unset($data['faq']);
}

// ---- Instagram ----
$igPosts = array_values(array_filter(array_map('trim', preg_split('/\R+/', (string) ($_POST['instagram_posts'] ?? '')) ?: []), fn($u) => $u !== ''));
if ($igPosts !== []) {
    $data['instagram_posts'] = $igPosts;
    $data['instagram_feed'] = true;
} else {
    unset($data['instagram_posts']);
    if (($_POST['instagram_feed'] ?? '') === '1' && !empty($data['instagram_handle'])) {
        $data['instagram_feed'] = true;
    } else {
        unset($data['instagram_feed']);
    }
}

// ---- Location ----
$location = [];
foreach (['street' => 'street', 'neighborhood' => 'neighborhood', 'city' => 'city', 'state' => 'state', 'zip' => 'zip', 'maps_embed_url' => 'maps_embed_url', 'studio_name' => 'studio_name'] as $field => $key) {
    $v = intake_str($field);
    if ($v === '') {
        continue;
    }
    // An iframe src must never be a javascript:/data: URL.
    if ($key === 'maps_embed_url' && !preg_match('#^https://#i', $v)) {
        continue;
    }
    $location[$key] = $v;
}
if ($location !== []) {
    $data['location'] = $location;
} else {
    unset($data['location']);
}

// ---- Opening hours ----
$opens = intake_str('opening_opens');
$closes = intake_str('opening_closes');
$days = array_values(array_filter(intake_list('opening_days'), fn($d) => $d !== ''));
if ($opens !== '' && $closes !== '' && $days !== []) {
    $data['opening_hours'] = ['days' => $days, 'opens' => $opens, 'closes' => $closes];
} else {
    unset($data['opening_hours']);
}

// ================================================================
// Media uploads
// ================================================================

// Main profile / hero photo and about photo (single uploads).
$profile = intake_file('profile_photo');
if ($profile !== []) {
    if (($profile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_INI_SIZE) {
        fail(422, 'Foto principal muito grande (limite de 15 MB).');
    }
    $saved = artist_save_upload($profile, $slug, 'hero');
    if ($saved !== '') {
        $data['profile_photo'] = $saved;
        $data['hero_photo'] = $saved;
    }
} elseif ($mode === 'create') {
    unset($data['profile_photo'], $data['hero_photo']);
}

$about = intake_file('about_photo');
if ($about !== []) {
    if (($about['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_INI_SIZE) {
        fail(422, 'Foto "sobre" muito grande (limite de 15 MB).');
    }
    $saved = artist_save_upload($about, $slug, 'about');
    if ($saved !== '') {
        $data['about_photo'] = $saved;
    }
} elseif ($mode === 'create') {
    unset($data['about_photo']);
}

// ---- Portfolio (repeated rows; keep existing files when not replaced) ----
$portfolioFiles = intake_files('portfolio_file');
$portfolioCurrents = intake_list('portfolio_current');
$portfolioAlts = intake_list('portfolio_alt');
$portfolio = [];
$slotCount = max(count($portfolioFiles), count($portfolioCurrents), count($portfolioAlts));
for ($i = 0; $i < $slotCount; $i++) {
    $file = $portfolioFiles[$i] ?? null;
    $alt = $portfolioAlts[$i] ?? '';
    $src = '';

    if ($file !== null) {
        if (($file['error'] ?? 0) === UPLOAD_ERR_INI_SIZE) {
            fail(422, 'Imagem de portfólio muito grande (limite de 15 MB).');
        }
        $saved = artist_save_upload($file, $slug, sprintf('portfolio-%02d', $i + 1));
        if ($saved !== '') {
            $src = $saved;
        }
    }
    if ($src === '' && $mode === 'update') {
        $src = $portfolioCurrents[$i] ?? '';
    }
    if ($src === '') {
        continue;
    }
    $portfolio[] = ['src' => $src, 'alt' => $alt !== '' ? $alt : 'Tatuagem do portfólio'];
}
if ($portfolio !== []) {
    $data['portfolio'] = $portfolio;
} else {
    unset($data['portfolio']);
}

// ---- Before/after (cover-ups) ----
if ($hasCoberturas) {
    $baBeforeFiles = intake_files('ba_before_file');
    $baAfterFiles = intake_files('ba_after_file');
    $baBeforeCurrent = intake_list('ba_before_current');
    $baAfterCurrent = intake_list('ba_after_current');
    $baTitles = intake_list('ba_title');
    $baCaptions = intake_list('ba_caption');

    $items = [];
    $slotCount = max(count($baBeforeFiles), count($baAfterFiles), count($baBeforeCurrent), count($baAfterCurrent), count($baTitles));
    for ($i = 0; $i < $slotCount; $i++) {
        $beforeFile = $baBeforeFiles[$i] ?? null;
        $afterFile = $baAfterFiles[$i] ?? null;
        $before = '';
        $after = '';

        if ($beforeFile !== null) {
            if (($beforeFile['error'] ?? 0) === UPLOAD_ERR_INI_SIZE) {
                fail(422, 'Imagem de cobertura muito grande (limite de 15 MB).');
            }
            $saved = artist_save_upload($beforeFile, $slug, sprintf('cobertura-%02d-antes', $i + 1));
            if ($saved !== '') {
                $before = $saved;
            }
        }
        if ($before === '' && $mode === 'update') {
            $before = $baBeforeCurrent[$i] ?? '';
        }

        if ($afterFile !== null) {
            if (($afterFile['error'] ?? 0) === UPLOAD_ERR_INI_SIZE) {
                fail(422, 'Imagem de cobertura muito grande (limite de 15 MB).');
            }
            $saved = artist_save_upload($afterFile, $slug, sprintf('cobertura-%02d-depois', $i + 1));
            if ($saved !== '') {
                $after = $saved;
            }
        }
        if ($after === '' && $mode === 'update') {
            $after = $baAfterCurrent[$i] ?? '';
        }

        if ($before === '' || $after === '') {
            continue;
        }

        $items[] = [
            'before'  => $before,
            'after'   => $after,
            'title'   => $baTitles[$i] ?? ('Cobertura ' . ($i + 1)),
            'caption' => $baCaptions[$i] ?? '',
        ];
    }

    if ($items !== []) {
        $data['before_after'] = [
            'tag'               => 'Especialidade',
            'heading'           => 'Coberturas',
            'heading_highlight' => 'antes e depois',
            'nav_label'         => 'Coberturas',
            'description'       => 'Uma seleção de coberturas. Arraste a barra dourada para revelar como cada tatuagem antiga ganhou uma nova história.',
            'items'             => $items,
        ];
    } else {
        unset($data['before_after']);
    }
}

// ================================================================
// Persist
// ================================================================

if ($mode === 'update') {
    artist_db_update($pdo, $id, $data);
    respond(200, [
        'success'     => true,
        'slug'        => $slug,
        'preview_url' => '/artists/' . $slug . '?preview=' . $existing['preview_token'],
        'url'         => '/artists/' . $slug,
    ]);
}

$previewToken = artist_preview_token();
$meta = [
    'submitter_name'    => intake_str('submitter_name'),
    'submitter_contact' => intake_str('submitter_contact'),
];
$newId = artist_db_insert($pdo, $slug, $data, $previewToken, $meta);

// Consume the invite token so it cannot be reused.
intake_token_mark_used($pdo, $intakeToken, $slug);

respond(200, [
    'success'     => true,
    'id'          => $newId,
    'slug'        => $slug,
    'preview_url' => '/artists/' . $slug . '?preview=' . $previewToken,
    'url'         => '/artists/' . $slug,
]);
