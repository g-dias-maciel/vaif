<?php

declare(strict_types=1);

/**
 * Articles about this artist. Reads $slug and $display_name; sets
 * $articles_section_html. Only published posts with a matching artist_slug
 * are shown.
 */

require_once __DIR__ . '/../../lib/blog/load-posts.php';

$articles_section_html = '';
$blogDir = (string) (getenv('BLOG_CONTENT_DIR') ?: __DIR__ . '/../../content/blog');
$allPosts = load_posts($blogDir);
$artistPosts = [];

foreach ($allPosts as $post) {
    if ($post['draft']) {
        continue;
    }
    if ((string) ($post['artist_slug'] ?? '') !== (string) $slug) {
        continue;
    }
    $artistPosts[] = $post;
}

if ($artistPosts !== []) {
    $artistPosts = array_slice($artistPosts, 0, 3);
    $cards = '';

    foreach ($artistPosts as $post) {
        $title = h($post['title']);
        $desc = h($post['description']);
        $url = '/blog/' . rawurlencode($post['slug']);
        $date = date('d/m/Y', strtotime($post['date']));
        $reading = (int) $post['reading_time'];

        $cards .= <<<CARD
            <article class="artist-article-card">
                <time datetime="{$post['date']}">{$date} · {$reading} min</time>
                <h3><a href="{$url}">{$title}</a></h3>
                <p>{$desc}</p>
                <a href="{$url}" class="artist-article-link">Ler artigo →</a>
            </article>
CARD;
    }

    $header = render_section_header('Conteúdo', 'Artigos sobre', $display_name);
    $articles_section_html = <<<HTML
    <section id="articles" class="artist-section artist-articles">
        <div class="container">
{$header}
        </div>
        <div class="artist-articles-grid">
{$cards}
        </div>
    </section>
HTML;
}
