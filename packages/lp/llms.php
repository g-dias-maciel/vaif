<?php

/**
 * /llms.txt — a plain-language map for AI assistants.
 *
 * Served dynamically so new posts and artist pages appear without a deploy.
 * Google does not consume llms.txt; other assistants and developer tools may.
 */

declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: public, max-age=300');

require_once __DIR__ . '/lib/artists/load.php';
require_once __DIR__ . '/lib/blog/load-posts.php';

$base = rtrim((string) (getenv('LP_BASE_URL') ?: 'https://vaif.com.br'), '/');
$artists = artist_list_published();
$blogDir = (string) (getenv('BLOG_CONTENT_DIR') ?: __DIR__ . '/content/blog');
$posts = load_posts($blogDir);

echo "# VAIF — Tatuadores\n\n";
echo "> VAIF é uma agência de crescimento para estúdios de tatuagem no Brasil. Esta página reúne os artistas atendidos pela VAIF, com estúdio, especialidades, endereço e contato. Os artistas atendem com hora marcada.\n\n";

echo "## Artistas\n\n";
foreach ($artists as $slug => $loaded) {
    $artist = $loaded['artist'];
    $name = (string) ($artist['display_name'] ?? $slug);
    $city = (string) ($artist['location']['city'] ?? '');
    $state = (string) ($artist['location']['state'] ?? '');
    $studio = (string) ($artist['location']['studio_name'] ?? '');
    $style = (string) ($artist['style'] ?? '');
    $bio = function_exists('artist_bio_to_plain')
        ? artist_bio_to_plain((string) ($artist['bio'] ?? ''))
        : trim(strip_tags((string) ($artist['bio'] ?? '')));
    $bio = mb_substr(preg_replace('/\s+/u', ' ', $bio) ?? '', 0, 280);
    $url = $base . '/artists/' . rawurlencode((string) $slug);
    $where = trim($city . ($state !== '' ? '/' . $state : ''));
    $line = '- [' . $name . '](' . $url . '): Tatuador' . ($where !== '' ? ' em ' . $where : '') . '.';
    if ($style !== '') {
        $line .= ' Estilos: ' . $style . '.';
    }
    if ($studio !== '') {
        $line .= ' Estúdio: ' . $studio . '.';
    }
    if ($bio !== '') {
        $line .= ' ' . $bio;
    }
    echo $line . "\n";
}

if ($posts !== []) {
    echo "\n## Artigos\n\n";
    foreach ($posts as $post) {
        if ($post['draft']) {
            continue;
        }
        $url = $base . '/blog/' . rawurlencode($post['slug']);
        $desc = trim((string) $post['description']);
        echo '- [' . $post['title'] . '](' . $url . ')' . ($desc !== '' ? ': ' . $desc : '') . "\n";
    }
}

echo "\n## Serviços\n\n";
echo "- Tatuagens de cobertura (cover-up) de tatuagens antigas ou indesejadas\n";
echo "- Realismo preto e cinza\n";
echo "- Orientação de orçamento e agendamento pelo WhatsApp\n";

echo "\n## Contato\n\n";
echo "- Site: {$base}\n";
echo "- Blog: {$base}/blog\n";
echo "- Instagram da agência: https://instagram.com/vaifmarketing\n";
