<?php

/**
 * Public artist profile feed for the CRM content workflow.
 *
 * GET /api/artists/profile.php?slug=adriano-santos
 *
 * Returns only public landing-page data. When LP_ARTIST_API_KEY is set, the
 * caller must send the same value in X-Api-Key.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');

require_once __DIR__ . '/../../lib/artists/load.php';
require_once __DIR__ . '/../../lib/artists/media.php';
require_once __DIR__ . '/../../lib/artists/specialty.php';

$expectedKey = getenv('LP_ARTIST_API_KEY');
if (is_string($expectedKey) && $expectedKey !== '') {
    $providedKey = (string) ($_SERVER['HTTP_X_API_KEY'] ?? '');
    if ($providedKey === '' || !hash_equals($expectedKey, $providedKey)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'API key inválida.']);
        exit;
    }
}

$slug = trim((string) ($_GET['slug'] ?? ''));
if ($slug === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Parâmetro slug é obrigatório.']);
    exit;
}

$loaded = artist_load_by_slug($slug);
if ($loaded === null || $loaded['status'] !== 'published') {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Artista não encontrado.']);
    exit;
}

$artist = $loaded['artist'];
$baseUrl = rtrim((string) (getenv('LP_BASE_URL') ?: 'https://vaif.com.br'), '/');
$landingUrl = $baseUrl . '/artists/' . rawurlencode($slug);

$styleList = array_values(array_filter(array_map(
    'trim',
    preg_split('/[,|]+/', (string) ($artist['style'] ?? '')) ?: []
)));

$bio = (string) ($artist['bio'] ?? '');
$bioPlain = function_exists('artist_bio_to_plain')
    ? artist_bio_to_plain($bio)
    : trim(html_entity_decode(strip_tags($bio), ENT_QUOTES, 'UTF-8'));

$portfolio = [];
foreach (($artist['portfolio'] ?? []) as $item) {
    if (!is_array($item) || empty($item['src'])) {
        continue;
    }
    $portfolio[] = [
        'src' => artist_media_url((string) $item['src'], $slug),
        'alt' => (string) ($item['alt'] ?? ''),
    ];
}

$beforeAfter = [];
foreach (($artist['before_after']['items'] ?? []) as $item) {
    if (!is_array($item) || empty($item['before']) || empty($item['after'])) {
        continue;
    }
    $beforeAfter[] = [
        'before' => artist_media_url((string) $item['before'], $slug),
        'after' => artist_media_url((string) $item['after'], $slug),
        'title' => (string) ($item['title'] ?? ''),
        'caption' => (string) ($item['caption'] ?? ''),
    ];
}

$testimonials = [];
foreach (($artist['testimonials'] ?? []) as $item) {
    if (!is_array($item) || empty($item['text'])) {
        continue;
    }
    $testimonials[] = [
        'name' => (string) ($item['name'] ?? ''),
        'meta' => (string) ($item['meta'] ?? ''),
        'text' => (string) $item['text'],
        'rating' => isset($item['rating']) ? (float) $item['rating'] : null,
    ];
}

$faq = [];
foreach (($artist['faq'] ?? []) as $item) {
    if (!is_array($item) || empty($item['question']) || empty($item['answer'])) {
        continue;
    }
    $faq[] = [
        'question' => (string) $item['question'],
        'answer' => (string) $item['answer'],
    ];
}

$stats = [];
foreach (($artist['stats'] ?? []) as $item) {
    if (!is_array($item) || !isset($item['value'])) {
        continue;
    }
    $stats[] = [
        'value' => (string) $item['value'],
        'label' => (string) ($item['label'] ?? ''),
    ];
}

$whatsappNumber = preg_replace('/\D+/', '', (string) ($artist['whatsapp_number'] ?? '')) ?: '';
$whatsappMessage = (string) ($artist['whatsapp_message'] ?? 'Olá, vim pelo site da VAIF.');
$instagramHandle = ltrim((string) ($artist['instagram_handle'] ?? ''), '@');

$profileImage = '';
if (!empty($artist['profile_photo'])) {
    $profileImage = artist_media_url((string) $artist['profile_photo'], $slug);
} elseif (!empty($portfolio[0]['src'])) {
    $profileImage = $portfolio[0]['src'];
}

$heroImage = '';
if (!empty($artist['hero_photo'])) {
    $heroImage = artist_media_url((string) $artist['hero_photo'], $slug);
}

$updatedAt = null;
if ($loaded['source'] === 'db' && !empty($loaded['db_row']['updated_at'])) {
    $updatedAt = (string) $loaded['db_row']['updated_at'];
} else {
    $configPath = artist_config_path($slug);
    if (is_file($configPath)) {
        $updatedAt = date('c', (int) filemtime($configPath));
    }
}

$payload = [
    'success' => true,
    'slug' => $slug,
    'display_name' => (string) ($artist['display_name'] ?? $slug),
    'source' => $loaded['source'],
    'landing_page_url' => $landingUrl,
    'city' => (string) ($artist['location']['city'] ?? ''),
    'state' => (string) ($artist['location']['state'] ?? ''),
    'neighborhood' => (string) ($artist['location']['neighborhood'] ?? ''),
    'studio_name' => (string) ($artist['location']['studio_name'] ?? ''),
    'address' => (string) ($artist['location']['street'] ?? ''),
    'zip' => (string) ($artist['location']['zip'] ?? ''),
    'geo' => [
        'latitude' => $artist['location']['lat'] ?? null,
        'longitude' => $artist['location']['lng'] ?? null,
    ],
    'styles' => $styleList,
    'style_summary' => implode(', ', array_slice($styleList, 0, 4)),
    'specialty' => artist_specialty($artist),
    'bio_plain' => $bioPlain,
    'price_range' => (string) ($artist['price_range'] ?? ''),
    'years_experience' => (string) ($artist['anos_de_experiencia'] ?? ''),
    'instagram_handle' => $instagramHandle,
    'instagram_url' => $instagramHandle !== '' ? 'https://instagram.com/' . $instagramHandle : '',
    'whatsapp_number' => $whatsappNumber,
    'whatsapp_url' => $whatsappNumber !== ''
        ? 'https://wa.me/' . $whatsappNumber . '?text=' . rawurlencode($whatsappMessage)
        : '',
    'profile_image' => $profileImage,
    'hero_image' => $heroImage,
    'og_image' => $profileImage,
    'portfolio' => $portfolio,
    'before_after' => $beforeAfter,
    'testimonials' => $testimonials,
    'faq' => $faq,
    'stats' => $stats,
    'opening_hours' => $artist['opening_hours'] ?? null,
    'updated_at' => $updatedAt,
];

echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
