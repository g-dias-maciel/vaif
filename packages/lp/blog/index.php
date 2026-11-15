<?php
/**
 * Blog front-controller — vaif.com.br/blog(/<slug>)
 *
 * Ticket: #23 — Blog System
 * Content pipeline: CRM → GitHub → this controller.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/blog/parse-frontmatter.php';
require_once __DIR__ . '/../lib/blog/render-markdown.php';
require_once __DIR__ . '/../lib/blog/slugify.php';
require_once __DIR__ . '/../lib/blog/load-posts.php';
require_once __DIR__ . '/../lib/artists/load.php';
require_once __DIR__ . '/../components/SeoHelpers.php';

$blogDir = (string) (getenv('BLOG_CONTENT_DIR') ?: __DIR__ . '/../content/blog');
$posts = load_posts($blogDir);

function blog_base_url(): string
{
    return rtrim((string) (getenv('LP_BASE_URL') ?: 'https://vaif.com.br'), '/');
}

function blog_absolute(string $path): string
{
    return blog_base_url() . '/' . ltrim($path, '/');
}

function blog_absolute_asset(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (preg_match('#^(https?:)?//#i', $url) === 1) {
        return $url;
    }

    return blog_base_url() . '/' . ltrim($url, '/');
}

function blog_author_type(string $author): string
{
    return (stripos($author, 'Equipe') === 0 || strcasecmp($author, 'VAIF') === 0)
        ? 'Organization'
        : 'Person';
}

/**
 * @param array<string, array<string, mixed>> $posts
 * @return array<int, array<string, mixed>>
 */
function blog_related_posts(array $posts, array $current, int $limit = 3): array
{
    $related = [];

    foreach ($posts as $post) {
        if ($post['draft'] || $post['slug'] === $current['slug']) {
            continue;
        }

        $score = 0;
        if (($current['artist_slug'] ?? '') !== '' && ($post['artist_slug'] ?? '') === $current['artist_slug']) {
            $score += 4;
        }
        if (($current['category'] ?? '') !== '' && ($post['category'] ?? '') === $current['category']) {
            $score += 2;
        }
        $score += count(array_intersect($current['tags'] ?? [], $post['tags'] ?? []));

        if ($score > 0) {
            $related[] = ['score' => $score, 'post' => $post];
        }
    }

    usort($related, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

    return array_map(fn (array $item): array => $item['post'], array_slice($related, 0, $limit));
}

/**
 * @param array<string, array<string, mixed>> $posts
 */
function render_rss(array $posts): string
{
    $base = blog_base_url();
    $items = '';
    $lastBuildDate = date(DATE_RSS);
    $first = true;

    foreach ($posts as $post) {
        if ($post['draft']) {
            continue;
        }

        $link = $base . '/blog/' . rawurlencode($post['slug']);
        $title = htmlspecialchars($post['title'], ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $description = htmlspecialchars($post['description'], ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $author = htmlspecialchars($post['author'], ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $pubDate = date(DATE_RSS, strtotime($post['date'] . ' 09:00:00'));
        if ($first) {
            $lastBuildDate = $pubDate;
            $first = false;
        }
        $category = $post['category'] !== ''
            ? '<category>' . htmlspecialchars($post['category'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</category>'
            : '';

        $items .= <<<ITEM
    <item>
        <title>{$title}</title>
        <link>{$link}</link>
        <guid isPermaLink="true">{$link}</guid>
        <pubDate>{$pubDate}</pubDate>
        <description>{$description}</description>
        <dc:creator>{$author}</dc:creator>
        {$category}
    </item>
ITEM;
    }

    return <<<RSS
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:atom="http://www.w3.org/2005/Atom">
<channel>
    <title>Blog VAIF</title>
    <link>{$base}/blog</link>
    <atom:link href="{$base}/blog/feed.xml" rel="self" type="application/rss+xml" />
    <description>Tatuagem, posicionamento e crescimento para artistas e estúdios.</description>
    <language>pt-BR</language>
    <lastBuildDate>{$pubDate}</lastBuildDate>
{$items}
</channel>
</rss>
RSS;
}

// ================================================================
// Helper: render full HTML page
// ================================================================

function render_page(string $title, string $body, string $extra_head = ''): string
{
    return <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title}</title>
    <meta name="robots" content="index, follow">
    <link rel="alternate" type="application/rss+xml" title="Blog VAIF" href="https://vaif.com.br/blog/feed.xml">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;600;700&family=Montserrat:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/style.css">
    <link rel="icon" href="/img/favicon/favicon.ico" sizes="any" type="image/x-icon">
    <link rel="icon" href="/img/favicon/favicon-16x16.png" sizes="16x16" type="image/png">
    <link rel="icon" href="/img/favicon/favicon-32x32.png" sizes="32x32" type="image/png">
    <link rel="apple-touch-icon" href="/img/favicon/apple-touch-icon.png">
    <link rel="icon" href="/img/favicon/android-chrome-192x192.png" sizes="192x192" type="image/png">
    <link rel="icon" href="/img/favicon/android-chrome-512x512.png" sizes="512x512" type="image/png">
    <link rel="manifest" href="/img/favicon/site.webmanifest">
    <script>
        !function(f,b,e,v,n,t,s)
        {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
        n.callMethod.apply(n,arguments):n.queue.push(arguments)};
        if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
        n.queue=[];t=b.createElement(e);t.async=!0;
        t.src=v;s=b.getElementsByTagName(e)[0];
        s.parentNode.insertBefore(t,s)}(window, document,'script',
        'https://connect.facebook.net/en_US/fbevents.js');
        fbq('init', '752550821217294');
        fbq('track', 'PageView');
    </script>
    <script>
      var _paq = window._paq = window._paq || [];
      _paq.push(['trackPageView']);
      _paq.push(['enableLinkTracking']);
      (function() {
        var u="//analytics.vaif.com.br/";
        _paq.push(['setTrackerUrl', u+'matomo.php']);
        _paq.push(['setSiteId', '1']);
        var d=document, g=d.createElement('script'), s=d.getElementsByTagName('script')[0];
        g.async=true; g.src=u+'matomo.js'; s.parentNode.insertBefore(g,s);
      })();
    </script>
    <noscript><img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id=752550821217294&ev=PageView&noscript=1"/></noscript>
    {$extra_head}
</head>
<body class="blog-page">
    <header class="blog-header">
        <nav class="navbar">
            <div class="container">
                <a href="/" class="nav-brand">
                    <img src="/img/vaif_logo.webp" alt="VAIF" class="nav-logo-img">
                    <span class="nav-tagline">Blog</span>
                </a>
                <ul class="nav-links" id="nav-links">
                    <li><a href="/blog" class="nav-link">Artigos</a></li>
                    <li><a href="/" class="nav-link">← Voltar ao site</a></li>
                </ul>
            </div>
        </nav>
    </header>
    {$body}
    <footer class="footer-extended">
        <div class="footer-bottom" style="border-top:1px solid var(--border-color);margin-top:0;max-width:1200px;margin-left:auto;margin-right:auto;padding-left:var(--space-md);padding-right:var(--space-md);">
            &copy; 2026 VAIF • Todos os direitos reservados • Feito para artistas que pensam como empresários.
        </div>
    </footer>
</body>
</html>
HTML;
}

// ================================================================
// Routing
// ================================================================

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

// Route: /blog/feed.xml (RSS 2.0)
if ($path === '/blog/feed.xml') {
    header('Content-Type: application/rss+xml; charset=utf-8');
    echo render_rss($posts);
    exit;
}

// Route: /blog (listing)
if ($path === '/blog' || $path === '/blog/') {
    $published = array_values(array_filter($posts, function (array $post): bool {
        return !$post['draft'];
    }));

    $cards = '';
    foreach ($published as $post) {
        $date = date('d/m/Y', strtotime($post['date']));
        $img = '';
        if ($post['featured_image'] !== '') {
            $imgSrc = htmlspecialchars($post['featured_image']);
            $imgAlt = htmlspecialchars((string) $post['featured_image_alt']);
            $img = "<img class=\"blog-card-img\" src=\"{$imgSrc}\" alt=\"{$imgAlt}\" loading=\"lazy\">";
        }

        $tagsHtml = '';
        foreach ($post['tags'] as $tag) {
            $t = htmlspecialchars(trim($tag));
            $tagsHtml .= "<span class=\"blog-tag\">{$t}</span>";
        }

        $categoryHtml = '';
        if ($post['category'] !== '') {
            $cat = htmlspecialchars($post['category']);
            $categoryHtml = "<span class=\"blog-category\">{$cat}</span>";
        }

        $title = htmlspecialchars($post['title']);
        $desc = htmlspecialchars($post['description']);
        $slug = htmlspecialchars($post['slug']);
        $reading = (int) $post['reading_time'];

        $cards .= <<<CARD
        <article class="blog-card">
            {$img}
            <div class="blog-card-content">
                {$categoryHtml}
                <h2 class="blog-card-title"><a href="/blog/{$slug}">{$title}</a></h2>
                <time class="blog-card-date">{$date} · {$reading} min de leitura</time>
                <p class="blog-card-excerpt">{$desc}</p>
                <div class="blog-card-tags">{$tagsHtml}</div>
            </div>
        </article>
CARD;
    }

    if ($cards === '') {
        $cards = <<<EMPTY
        <div class="blog-empty">
            <p>Novos artigos em breve. Enquanto isso, conheça a VAIF.</p>
            <a href="/" class="btn-primary">Conhecer a VAIF</a>
        </div>
EMPTY;
    }

    $body = <<<HTML
    <main class="blog-listing">
        <h1 class="blog-listing-header">Blog VAIF</h1>
        <p class="blog-listing-sub">Tatuagem, posicionamento e crescimento para artistas e estúdios.</p>
        <div class="blog-listing-grid">
            {$cards}
        </div>
    </main>
HTML;

    $extra_head = <<<OG
    <link rel="canonical" href="https://vaif.com.br/blog">
    <meta property="og:title" content="Blog — VAIF">
    <meta property="og:description" content="Tatuagem, posicionamento e crescimento para artistas e estúdios.">
    <meta property="og:image" content="https://vaif.com.br/img/vaif_logo.png">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://vaif.com.br/blog">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="Blog — VAIF">
    <meta name="twitter:description" content="Tatuagem, posicionamento e crescimento para artistas e estúdios.">
    <meta name="twitter:image" content="https://vaif.com.br/img/vaif_logo.png">
OG;
    $extra_head .= "\n" . generateBlogJsonLd(array_map(function (array $post): array {
        $post['featured_image'] = blog_absolute_asset((string) $post['featured_image']);
        return $post;
    }, $published));

    echo render_page('Blog — VAIF', $body, $extra_head);
    exit;
}

// Route: /blog/<slug> (single post)
if (preg_match('#^/blog/([a-z0-9\-]+)/?$#', $path, $m)) {
    $slug = $m[1];

    if (!isset($posts[$slug])) {
        goto not_found;
    }

    $post = $posts[$slug];

    if ($post['draft']) {
        goto not_found;
    }

    $title = htmlspecialchars($post['title']);
    $date = date('d/m/Y', strtotime($post['date']));
    $author = htmlspecialchars($post['author']);
    $authorUrl = htmlspecialchars((string) $post['author_url']);
    $authorHtml = $author !== ''
        ? ($authorUrl !== ''
            ? " por <a href=\"{$authorUrl}\" rel=\"author\">{$author}</a>"
            : " por {$author}")
        : '';
    $bodyHtml = render_markdown($post['body']);
    $description = htmlspecialchars($post['description']);
    $siteSlug = htmlspecialchars($slug);
    $reading = (int) $post['reading_time'];

    $updatedHtml = '';
    if ($post['updated'] !== '' && $post['updated'] !== $post['date']) {
        $updatedHtml = ' · atualizado em ' . date('d/m/Y', strtotime($post['updated']));
    }

    $metaImg = '';
    if ($post['featured_image'] !== '') {
        $imgSrc = htmlspecialchars($post['featured_image']);
        $imgAlt = htmlspecialchars((string) $post['featured_image_alt']);
        $metaImg = "<img class=\"blog-post-featured-img\" src=\"{$imgSrc}\" alt=\"{$imgAlt}\">";
    }

    $tagsHtml = '';
    foreach ($post['tags'] as $tag) {
        $t = htmlspecialchars(trim($tag));
        $tagsHtml .= "<span class=\"blog-tag\">{$t}</span>";
    }

    $categoryHtml = '';
    if ($post['category'] !== '') {
        $cat = htmlspecialchars($post['category']);
        $categoryHtml = "<span class=\"blog-category\">{$cat}</span>";
    }

    $ogImage = '';
    if ($post['featured_image'] !== '') {
        $imgUrl = htmlspecialchars(blog_absolute_asset((string) $post['featured_image']));
        $ogImage = "<meta property=\"og:image\" content=\"{$imgUrl}\">
    <meta name=\"twitter:image\" content=\"{$imgUrl}\">";
    }

    // Artist entity for the article, when it is tied to a landing page.
    $artistData = null;
    if (($post['artist_slug'] ?? '') !== '') {
        $artistData = artist_load_by_slug((string) $post['artist_slug']);
        if ($artistData !== null && $artistData['status'] !== 'published') {
            $artistData = null;
        }
    }

    $artistCta = '';
    $seoAbout = null;
    $seoMentions = null;
    if ($artistData !== null) {
        $artist = $artistData['artist'];
        $artistName = (string) ($artist['display_name'] ?? $post['artist_slug']);
        $artistUrl = blog_absolute('/artists/' . rawurlencode((string) $post['artist_slug']));
        $city = (string) ($artist['location']['city'] ?? '');
        $studio = (string) ($artist['location']['studio_name'] ?? '');
        $hName = htmlspecialchars($artistName);
        $hCity = htmlspecialchars($city);
        $hUrl = htmlspecialchars($artistUrl);
        $hStudio = htmlspecialchars($studio);

        $seoAbout = [
            '@type' => 'Person',
            'name' => $artistName,
            'url' => $artistUrl,
        ];
        $studioInstagram = '';
        if (! empty($artist['instagram_handle'])) {
            $studioInstagram = 'https://instagram.com/' . ltrim((string) $artist['instagram_handle'], '@');
            $seoAbout['sameAs'] = [$studioInstagram];
        }
        if ($studio !== '') {
            $seoMentions = [
                '@type' => 'TattooParlor',
                'name' => $studio,
                'url' => $artistUrl,
            ];
            if ($studioInstagram !== '') {
                $seoMentions['sameAs'] = [$studioInstagram];
            }
        }

        $cityLine = $hCity !== '' ? " em {$hCity}" : '';
        $studioLine = $hStudio !== '' ? " no {$hStudio}" : '';
        $artistCta = <<<CTA
        <aside class="blog-artist-cta">
            <span class="blog-artist-eyebrow">Artista em destaque</span>
            <h2>Conheça {$hName}</h2>
            <p>Tatuador{$cityLine}{$studioLine}. Veja portfólio, especialidades e agenda.</p>
            <a href="{$hUrl}" class="btn-primary">Ver perfil de {$hName}</a>
        </aside>
CTA;
    }

    // Related posts: same artist, category or tags.
    $related = blog_related_posts($posts, $post, 3);
    $relatedHtml = '';
    if ($related !== []) {
        $items = '';
        foreach ($related as $item) {
            $rTitle = htmlspecialchars($item['title']);
            $rDesc = htmlspecialchars($item['description']);
            $rSlug = htmlspecialchars($item['slug']);
            $rDate = date('d/m/Y', strtotime($item['date']));
            $items .= <<<REL
            <article class="blog-related-card">
                <time>{$rDate}</time>
                <h3><a href="/blog/{$rSlug}">{$rTitle}</a></h3>
                <p>{$rDesc}</p>
            </article>
REL;
        }

        $relatedHtml = <<<RELATED
        <section class="blog-related">
            <h2>Continue lendo</h2>
            <div class="blog-related-grid">
                {$items}
            </div>
        </section>
RELATED;
    }

    $sourcesHtml = '';
    if (!empty($post['sources'])) {
        $sourceItems = '';
        foreach ($post['sources'] as $source) {
            $hSource = htmlspecialchars($source);
            if (preg_match('#^https?://#i', $source)) {
                $sourceItems .= "<li><a href=\"{$hSource}\" rel=\"nofollow noopener\" target=\"_blank\">{$hSource}</a></li>";
            } else {
                $sourceItems .= "<li>{$hSource}</li>";
            }
        }
        $sourcesHtml = "<section class=\"blog-sources\"><h2>Fontes</h2><ul>{$sourceItems}</ul></section>";
    }

    $body = <<<HTML
    <main class="blog-post">
        {$metaImg}
        {$categoryHtml}
        <h1 class="blog-post-title">{$title}</h1>
        <div class="blog-post-meta">
            <time datetime="{$post['date']}">{$date}</time>{$authorHtml} · {$reading} min de leitura{$updatedHtml}
        </div>
        <div class="blog-post-body">
            {$bodyHtml}
        </div>
        {$sourcesHtml}
        <div class="blog-post-tags">{$tagsHtml}</div>
        {$artistCta}
        {$relatedHtml}
        <nav class="blog-post-nav">
            <a href="/blog" class="btn-secondary">← Todos os artigos</a>
        </nav>
    </main>
HTML;

    $articleTags = '';
    foreach ($post['tags'] as $tag) {
        $articleTags .= '<meta property="article:tag" content="' . htmlspecialchars(trim($tag)) . '">' . "\n    ";
    }

    $extra_head = <<<OG
    <link rel="canonical" href="https://vaif.com.br/blog/{$siteSlug}">
    <meta property="og:title" content="{$title} — VAIF Blog">
    <meta property="og:description" content="{$description}">
    <meta property="og:type" content="article">
    <meta property="og:url" content="https://vaif.com.br/blog/{$siteSlug}">
    <meta property="article:published_time" content="{$post['date']}T09:00:00-03:00">
    <meta property="article:modified_time" content="{$post['updated']}T09:00:00-03:00">
    <meta property="article:author" content="{$author}">
    {$articleTags}
    {$ogImage}
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{$title} — VAIF Blog">
    <meta name="twitter:description" content="{$description}">
OG;

    $seoPost = [
        'title' => $post['title'],
        'description' => $post['description'],
        'author' => $post['author'],
        'author_type' => blog_author_type((string) $post['author']),
        'author_url' => $post['author_url'],
        'datePublished' => date('c', strtotime($post['date'] . ' 09:00:00')),
        'dateModified' => date('c', strtotime(($post['updated'] ?: $post['date']) . ' 09:00:00')),
        'url' => 'https://vaif.com.br/blog/' . $post['slug'],
        'mainEntityOfPage' => 'https://vaif.com.br/blog/' . $post['slug'],
        'articleSection' => $post['category'],
        'keywords' => implode(', ', $post['tags']),
        'wordCount' => $post['word_count'],
        'timeRequired' => 'PT' . max(1, (int) $post['reading_time']) . 'M',
    ];
    if ($post['featured_image'] !== '') {
        $seoPost['image'] = blog_absolute_asset((string) $post['featured_image']);
    }
    if ($seoAbout !== null) {
        $seoPost['about'] = $seoAbout;
    }
    if ($seoMentions !== null) {
        $seoPost['mentions'] = $seoMentions;
    }

    $extra_head .= "\n" . generateBlogPostingJsonLd($seoPost);
    if (!empty($post['faq'])) {
        $extra_head .= "\n" . generateFaqPageJsonLd($post['faq']);
    }
    $extra_head .= "\n" . generateBreadcrumbListJsonLd([
        ['name' => 'Home', 'url' => 'https://vaif.com.br/'],
        ['name' => 'Blog', 'url' => 'https://vaif.com.br/blog'],
        ['name' => $post['title'], 'url' => 'https://vaif.com.br/blog/' . $post['slug']],
    ]);

    echo render_page("{$title} — VAIF Blog", $body, $extra_head);
    exit;
}

// Route: 404 fallback
not_found:
http_response_code(404);

$body = <<<HTML
<main class="blog-404">
    <div class="blog-404-card">
        <h1>Página não encontrada</h1>
        <p>O artigo que você procura não existe ou foi removido.</p>
        <a href="/blog" class="btn-primary">Ver todos os artigos</a>
    </div>
</main>
HTML;

echo render_page('Não Encontrado — VAIF Blog', $body);
