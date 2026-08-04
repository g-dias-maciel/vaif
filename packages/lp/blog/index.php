<?php
/**
 * Blog front-controller — vaif.com.br/blog(/<slug>)
 *
 * Ticket: #23 — Blog System
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/blog/parse-frontmatter.php';
require_once __DIR__ . '/../lib/blog/render-markdown.php';
require_once __DIR__ . '/../lib/blog/slugify.php';
require_once __DIR__ . '/../lib/blog/load-posts.php';
require_once __DIR__ . '/../components/SeoHelpers.php';

$posts = load_posts(__DIR__ . '/../content/blog');

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
                    <img src="/img/vaif_logo.png" alt="VAIF" class="nav-logo-img">
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

// Route: /blog (listing)
if ($path === '/blog' || $path === '/blog/') {
    $published = array_filter($posts, function (array $post): bool {
        return !$post['draft'];
    });

    $cards = '';
    foreach ($published as $post) {
        $date = date('d/m/Y', strtotime($post['date']));
        $img = '';
        if ($post['featured_image'] !== '') {
            $imgSrc = htmlspecialchars($post['featured_image']);
            $img = "<img class=\"blog-card-img\" src=\"{$imgSrc}\" alt=\"\" loading=\"lazy\">";
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

        $cards .= <<<CARD
        <article class="blog-card">
            {$img}
            <div class="blog-card-content">
                {$categoryHtml}
                <h2 class="blog-card-title"><a href="/blog/{$slug}">{$title}</a></h2>
                <time class="blog-card-date">{$date}</time>
                <p class="blog-card-excerpt">{$desc}</p>
                <div class="blog-card-tags">{$tagsHtml}</div>
            </div>
        </article>
CARD;
    }

    $body = <<<HTML
    <main class="blog-listing">
        <h1 class="blog-listing-header">Blog VAIF</h1>
        <p class="blog-listing-sub">Insights sobre automação, marketing e crescimento para estúdios de tatuagem.</p>
        <div class="blog-listing-grid">
            {$cards}
        </div>
    </main>
HTML;

    $extra_head = <<<OG
    <link rel="canonical" href="https://vaif.com.br/blog">
    <meta property="og:title" content="Blog — VAIF">
    <meta property="og:description" content="Insights sobre automação, marketing e crescimento para estúdios de tatuagem.">
    <meta property="og:image" content="https://vaif.com.br/img/vaif_logo.png">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://vaif.com.br/blog">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="Blog — VAIF">
    <meta name="twitter:description" content="Insights sobre automação, marketing e crescimento para estúdios de tatuagem.">
    <meta name="twitter:image" content="https://vaif.com.br/img/vaif_logo.png">
OG;
    echo render_page('Blog — VAIF', $body, $extra_head);
    exit;
}

// Route: /blog/<slug> (single post)
if (preg_match('#^/blog/([a-z0-9\-]+)$#', $path, $m)) {
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
    $authorHtml = $author !== '' ? " por {$author}" : '';
    $bodyHtml = render_markdown($post['body']);
    $description = htmlspecialchars($post['description']);
    $siteSlug = htmlspecialchars($slug);

    $metaImg = '';
    if ($post['featured_image'] !== '') {
        $imgSrc = htmlspecialchars($post['featured_image']);
        $metaImg = "<img class=\"blog-post-featured-img\" src=\"{$imgSrc}\" alt=\"{$title}\">";
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
        $imgUrl = htmlspecialchars($post['featured_image']);
        $ogImage = "<meta property=\"og:image\" content=\"{$imgUrl}\">
    <meta name=\"twitter:image\" content=\"{$imgUrl}\">";
    }

    $body = <<<HTML
    <main class="blog-post">
        {$metaImg}
        {$categoryHtml}
        <h1 class="blog-post-title">{$title}</h1>
        <div class="blog-post-meta">
            <time>{$date}</time>{$authorHtml}
        </div>
        <div class="blog-post-body">
            {$bodyHtml}
        </div>
        <div class="blog-post-tags">{$tagsHtml}</div>
        <nav class="blog-post-nav">
            <a href="/blog" class="btn-secondary">← Todos os artigos</a>
        </nav>
    </main>
HTML;

    $extra_head = <<<OG
    <link rel="canonical" href="https://vaif.com.br/blog/{$siteSlug}">
    <meta property="og:title" content="{$title} — VAIF Blog">
    <meta property="og:description" content="{$description}">
    <meta property="og:type" content="article">
    <meta property="og:url" content="https://vaif.com.br/blog/{$siteSlug}">
    {$ogImage}
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{$title} — VAIF Blog">
    <meta name="twitter:description" content="{$description}">
OG;

    $seoPost = [
        'title' => $post['title'],
        'description' => $post['description'],
        'author' => $post['author'],
        'datePublished' => date('c', strtotime($post['date'])),
        'dateModified' => date('c', strtotime($post['date'])),
        'url' => 'https://vaif.com.br/blog/' . $post['slug'],
        'mainEntityOfPage' => 'https://vaif.com.br/blog/' . $post['slug'],
    ];
    if ($post['featured_image'] !== '') {
        $seoPost['image'] = $post['featured_image'];
    }

    $extra_head .= "\n" . generateBlogPostingJsonLd($seoPost);
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
