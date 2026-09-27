<?php

declare(strict_types=1);

/**
 * Instagram feed (official oEmbed embeds, or placeholders).
 * Sets $instagram_section_html and $instagram_embed_script.
 * Requires lib/instagram/embed-url.php and render_section_header().
 */
$instagram_grid_html = '';
$instagram_embed_script = '';

$instagram_posts = $artist['instagram_posts'] ?? [];

if (!empty($instagram_posts)) {
    foreach ($instagram_posts as $post) {
        $post_url = is_array($post) ? ($post['url'] ?? '') : $post;
        if ($post_url === '') {
            continue;
        }
        $embed_url = instagram_embed_url($post_url);
        $post_url_h = h($embed_url);
        $is_reel = str_contains($embed_url, '/reel/');
        $extra_class = $is_reel ? ' instagram-embed--reel' : '';
        $instagram_grid_html .= <<<HTML
            <blockquote class="instagram-media{$extra_class}" data-instgrm-permalink="{$post_url_h}" data-instgrm-captioned="false"></blockquote>

HTML;
    }
    $instagram_embed_script = '<script async src="https://www.instagram.com/embed.js"></script>';
} elseif ($show_instagram) {
    for ($i = 1; $i <= 8; $i++) {
        $instagram_grid_html .= <<<HTML
            <div class="instagram-item">
                <img src="https://placehold.co/300x300/1a1a1a/D4B04C?text=Post+{$i}&font=montserrat" alt="Instagram post {$i}" loading="lazy">
            </div>

HTML;
    }
}

$instagram_section_html = '';
if ($show_instagram) {
    $ig_handle_h = h($instagram_handle);
    $ig_url_h = h($instagram_url);
    $header = render_section_header('Instagram', 'Acompanhe o', 'dia a dia');
    $instagram_section_html = <<<HTML
    <!-- ═══ SECTION 6: INSTAGRAM FEED ═══ -->
    <section id="instagram" class="artist-section">
        <div class="container">
{$header}
        </div>
        <div class="instagram-grid">
{$instagram_grid_html}        </div>
        <div class="instagram-handle">
            <a href="{$ig_url_h}" target="_blank" rel="noopener noreferrer">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="2" y="2" width="20" height="20" rx="5" ry="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"/></svg>
                @{$ig_handle_h}
            </a>
        </div>
        <div class="instagram-followers">Siga no Instagram</div>
    </section>

HTML;
}
