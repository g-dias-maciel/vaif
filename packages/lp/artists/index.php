<?php

declare(strict_types=1);

// ================================================================
// Routing + Config Loading
// ================================================================

require_once __DIR__ . '/sections/error-404.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

$slug = null;
if (preg_match('#^/artists/([a-zA-Z0-9][a-zA-Z0-9\-]*[a-zA-Z0-9])$#', $path, $matches)) {
    $slug = $matches[1];
} elseif (preg_match('#^/([a-zA-Z0-9][a-zA-Z0-9\-]*[a-zA-Z0-9])$#', $path, $matches)) {
    $slug = $matches[1];
}

if ($slug === null) {
    http_response_code(404);
    render_404();
    exit;
}

$configPath = __DIR__ . "/config/{$slug}.php";

if (!file_exists($configPath)) {
    http_response_code(404);
    render_404();
    exit;
}

$artist = include $configPath;

if (!is_array($artist) || empty($artist['slug']) || empty($artist['display_name']) || empty($artist['whatsapp_number'])) {
    http_response_code(500);
    echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><title>Erro — VAIF</title></head><body style="background:#0A0A0A;color:rgb(242,237,228);font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;text-align:center;"><div><h1>Erro de Configuração</h1><p>Campos obrigatórios ausentes no arquivo de configuração do artista.</p></div></body></html>';
    exit;
}

// ================================================================
// Helpers + libraries
// ================================================================

function h(string $str): string
{
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

function image_url(string $img, string $slug): string
{
    return artist_media_url($img, $slug);
}

require_once __DIR__ . '/../lib/artists/media.php';
require_once __DIR__ . '/../lib/artists/specialty.php';
require_once __DIR__ . '/../lib/artists/before_after.php';
require_once __DIR__ . '/../lib/instagram/embed-url.php';
require_once __DIR__ . '/../components/SeoHelpers.php';

// ================================================================
// Default value computation
// ================================================================

$style_list = array_map('trim', preg_split('/[,|]+/', $artist['style'] ?? 'Tatuador'));
$primary_style = $style_list[0];
$style_summary = implode(', ', array_slice($style_list, 0, 4));

$display_name = $artist['display_name'];
$whatsapp_number = preg_replace('/[^0-9]/', '', $artist['whatsapp_number']);
$whatsapp_message = $artist['whatsapp_message'] ?? 'Olá, vim pelo seu site no vaif.com.br';
$whatsapp_text = rawurlencode($whatsapp_message);
$whatsapp_url = "https://wa.me/{$whatsapp_number}?text={$whatsapp_text}";
$instagram_handle = $artist['instagram_handle'] ?? '';
$instagram_url = $instagram_handle ? "https://instagram.com/{$instagram_handle}" : '';

$hero_headline = $artist['hero_headline'] ?? "{$display_name} — Tatuador {$primary_style}";
$hero_subheadline = $artist['hero_subheadline'] ?? "{$display_name}, especialista em {$primary_style} em {$artist['location']['city']}. Agende sua sessão pelo WhatsApp.";

$cta_text = $artist['cta_text'] ?? 'Agende sua sessão pelo WhatsApp';
$anos_de_experiencia = $artist['anos_de_experiencia'] ?? '8';

$city = $artist['location']['city'] ?? '';
$title_suffix = $city ? " em {$city}" : '';
$page_title = "{$display_name} — Tatuador {$primary_style}{$title_suffix} | VAIF";

$meta_description = "{$display_name} — Tatuador em {$city}. Especialista em {$style_summary}. Agende sua sessão pelo WhatsApp.";

// ── About stats (config-driven; never fabricated) ──────────────
$stats_items = [];
if (!empty($artist['stats']) && is_array($artist['stats'])) {
    foreach ($artist['stats'] as $stat) {
        if (isset($stat['value']) && $stat['value'] !== '') {
            $stats_items[] = [
                'value' => (string) $stat['value'],
                'label' => (string) ($stat['label'] ?? ''),
            ];
        }
    }
}
if ($stats_items === []) {
    $stats_items[] = ['value' => $anos_de_experiencia . '+', 'label' => 'Anos de Experiência'];
}

$testimonial_ratings = [];
foreach ($artist['testimonials'] ?? [] as $t) {
    if (isset($t['rating']) && is_numeric($t['rating'])) {
        $testimonial_ratings[] = (float) $t['rating'];
    }
}
$average_rating = $testimonial_ratings
    ? round(array_sum($testimonial_ratings) / count($testimonial_ratings), 2)
    : null;

// Prefer an explicit rating stat, else fall back to the testimonials average.
$rating_value = null;
foreach ($artist['stats'] ?? [] as $stat) {
    if (isset($stat['label'], $stat['value'])
        && mb_stripos((string) $stat['label'], 'avalia') !== false
        && is_numeric($stat['value'])) {
        $rating_value = (float) $stat['value'];
    }
}
if ($rating_value === null) {
    $rating_value = $average_rating;
}

$og_image = '';
if (!empty($artist['profile_photo'])) {
    $og_image = image_url($artist['profile_photo'], $slug);
} elseif (!empty($artist['portfolio'][0]['src'])) {
    $og_image = image_url($artist['portfolio'][0]['src'], $slug);
}

// ================================================================
// Section visibility flags
// ================================================================

$show_hero = true;
$show_portfolio = !empty($artist['portfolio']);
$artist_specialty = artist_specialty($artist);
$specialty_label = $artist_specialty !== '' ? mb_convert_case($artist_specialty, MB_CASE_TITLE, 'UTF-8') : 'Antes e Depois';
$show_before_after = artist_show_before_after($artist);
$show_about = !empty($artist['bio']);
$show_testimonials = !empty($artist['testimonials']);
$show_instagram = !empty($artist['instagram_feed']) || !empty($artist['instagram_posts']);
$show_faq = true;
$show_location = !empty($artist['location']);
$show_booking = true;

// ================================================================
// FAQ merging (artist overrides win; otherwise defaults + extras)
// ================================================================

$default_faqs = [
    [
        'question' => 'Como funciona o processo de orçamento?',
        'answer'   => 'Você me envia uma mensagem no WhatsApp com sua ideia, referências visuais, tamanho aproximado e região do corpo. Eu analiso e respondo em até 24 horas com valor, número estimado de sessões e disponibilidade de agenda. O orçamento é gratuito e sem compromisso.',
    ],
    [
        'question' => 'Quanto tempo dura uma sessão?',
        'answer'   => 'Cada sessão dura entre 4 e 6 horas de agulha, com pausas para seu conforto. Costumo agendar uma sessão por dia para garantir atenção total a cada cliente.',
    ],
    [
        'question' => 'Como é o cuidado pós-tatuagem?',
        'answer'   => 'Ao final da sessão, você recebe um kit de cuidados completo (pomada cicatrizante, instruções impressas e filme protetor). Também fico disponível no WhatsApp para qualquer dúvida durante o período de cicatrização — que dura em média 15 a 30 dias.',
    ],
    [
        'question' => 'Você faz cobertura de tatuagem?',
        'answer'   => 'Sim! Coberturas (cover-ups) são uma especialidade que exige técnica avançada. Preciso avaliar a tatuagem antiga pessoalmente ou por foto para definir a viabilidade e o desenho ideal. Agende uma consulta gratuita pelo WhatsApp.',
    ],
    [
        'question' => 'Precisa de sinal para agendar?',
        'answer'   => 'Sim. Para reservar sua data, peço um sinal de 30% do valor total via Pix ou transferência. O saldo é pago no dia da sessão. O sinal é reembolsável com até 72 horas de antecedência em caso de cancelamento.',
    ],
];

$artist_faqs = $artist['faq'] ?? [];
$merged_faqs = $default_faqs;

foreach ($artist_faqs as $artist_faq) {
    $found = false;
    foreach ($merged_faqs as $i => $default_faq) {
        if ($default_faq['question'] === $artist_faq['question']) {
            $merged_faqs[$i] = $artist_faq;
            $found = true;
            break;
        }
    }
    if (!$found) {
        $merged_faqs[] = $artist_faq;
    }
}

// ================================================================
// Hero / about media + reused WhatsApp icon
// ================================================================

$artist_media_dir = __DIR__ . "/{$slug}/media";
$hero_img_url = artist_preferred_photo($artist, 'hero_photo', $slug, $artist_media_dir);
$about_img_url = artist_preferred_photo($artist, 'about_photo', $slug, $artist_media_dir);

$wa_svg = '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>';

// ================================================================
// Section partials (order matters) — each sets its own *$…_html* variable
// ================================================================

require_once __DIR__ . '/sections/section-header.php';
require_once __DIR__ . '/sections/hero.php';
require_once __DIR__ . '/sections/faq.php';
require_once __DIR__ . '/sections/nav.php';
require_once __DIR__ . '/sections/jsonld.php';
require_once __DIR__ . '/sections/specialty-tags.php';
require_once __DIR__ . '/sections/portfolio.php';
require_once __DIR__ . '/sections/about.php';
require_once __DIR__ . '/sections/before-after.php';
require_once __DIR__ . '/sections/testimonials.php';
require_once __DIR__ . '/sections/instagram.php';
require_once __DIR__ . '/sections/location.php';

// ================================================================
// Escaped values for inline HTML
// ================================================================

$h_display_name = h($display_name);
$h_hero_headline = h($hero_headline);
$h_hero_subheadline = h($hero_subheadline);
$h_primary_style = h($primary_style);
$h_whatsapp_url = h($whatsapp_url);
$h_slug = h($slug);
$h_cta_text = h($cta_text);
$page_title_h = h($page_title);
$meta_description_h = h($meta_description);
$og_image_h = h($og_image);

// ================================================================
// RENDER FULL PAGE
// ================================================================

echo <<<PAGE
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$page_title_h}</title>
    <meta name="description" content="{$meta_description_h}">
    <link rel="canonical" href="https://vaif.com.br/artists/{$h_slug}">
    <meta property="og:title" content="{$h_hero_headline} | VAIF">
    <meta property="og:description" content="{$meta_description_h}">
    <meta property="og:image" content="{$og_image_h}">
    <meta property="og:type" content="website">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{$h_hero_headline} | VAIF">
    <meta name="twitter:description" content="{$meta_description_h}">
    <meta name="twitter:image" content="{$og_image_h}">
    {$jsonld_html}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,600;0,700;1,400&family=Montserrat:wght@300;400;500;600;700&display=swap" rel="stylesheet">

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
    <link rel="icon" href="/img/favicon/favicon.ico" sizes="any" type="image/x-icon">
    <link rel="icon" href="/img/favicon/favicon-16x16.png" sizes="16x16" type="image/png">
    <link rel="icon" href="/img/favicon/favicon-32x32.png" sizes="32x32" type="image/png">
    <link rel="apple-touch-icon" href="/img/favicon/apple-touch-icon.png">
    <link rel="icon" href="/img/favicon/android-chrome-192x192.png" sizes="192x192" type="image/png">
    <link rel="icon" href="/img/favicon/android-chrome-512x512.png" sizes="512x512" type="image/png">
    <link rel="manifest" href="/img/favicon/site.webmanifest">
    <link rel="stylesheet" href="/artists/artist.css">
</head>
<body>
    <a href="#main-content" class="skip-link">Pular para o conteúdo</a>

    <!-- ─── NAV ─── -->
    <nav class="navbar" aria-label="Navegação principal">
        <div class="container">
            <a href="/" class="nav-brand">
                <span class="nav-logo">VAIF</span>
                <span class="nav-tagline">Artistas de Elite</span>
            </a>
            <button class="nav-hamburger" id="nav-hamburger" aria-label="Abrir menu" aria-expanded="false">
                <span></span><span></span><span></span>
            </button>
            <ul class="nav-links" id="nav-links">
{$nav_links_html}                <li><a href="#booking" class="nav-btn-highlight">Agendar Sessão</a></li>
            </ul>
        </div>
    </nav>

    <main id="main-content">
    <!-- ═══ SECTION 1: HERO ═══ -->
    <section id="hero" class="artist-section">
        <div class="hero-bg">
            {$hero_bg_html}
        </div>
        <div class="hero-content-wrapper">
            <span class="hero-eyebrow">{$h_hero_headline}</span>
            <h1 class="hero-title">{$hero_name_html}</h1>
            <p class="hero-subtitle">
                {$h_hero_subheadline}
            </p>
            <div class="hero-ctas">
                <a href="#booking" class="btn-primary">
                    {$wa_svg}
                    {$h_cta_text}
                </a>
            </div>
        </div>
    </section>

{$portfolio_section_html}{$before_after_section_html}{$about_html}
    <!-- ═══ SECTION 4: BOOKING CTA ═══ -->
    <section id="booking" class="artist-section">
        <div class="booking-box">
            <span class="booking-label">Agende Sua Sessão</span>
            <h2>Pronto para eternizar sua <span>história na pele</span>?</h2>
            <p>
                Meu atendimento é 100% via WhatsApp. Envie uma mensagem com sua ideia,
                referências e região do corpo. Respondo pessoalmente em até 24 horas com
                orçamento e disponibilidade de agenda.
            </p>
            <a href="{$h_whatsapp_url}"
               class="whatsapp-btn"
               target="_blank"
               rel="noopener noreferrer"
               onclick="_paq.push(['trackEvent', 'Artista', 'CTA_WhatsApp', '{$h_slug}'])">
                {$wa_svg}
                Chamar no WhatsApp
            </a>
        </div>
    </section>

{$testimonials_section_html}{$instagram_section_html}
    <!-- ═══ SECTION 7: FAQ ═══ -->
    <section id="faq" class="artist-section">
        <div class="container">
            <div class="section-header">
                <span class="section-tag">Dúvidas Frequentes</span>
                <h2 class="section-heading">Perguntas que <span>sempre recebo</span></h2>
                <div class="diamond-divider">
                    <span class="line"></span><span class="diamond"></span><span class="line"></span>
                </div>
            </div>
        </div>
        <div class="faq-list">
{$faq_items_html}        </div>
    </section>

{$location_html}
    <!-- ═══ CLOSING CTA ═══ -->
    <section class="closing-cta">
        <h2 class="section-heading">Sua próxima <span>obra de arte</span> começa aqui</h2>
        <p>Envie sua ideia agora e receba um orçamento personalizado em até 24 horas.</p>
        <a href="{$h_whatsapp_url}"
           class="whatsapp-btn"
           target="_blank"
           rel="noopener noreferrer"
           onclick="_paq.push(['trackEvent', 'Artista', 'CTA_WhatsApp', '{$h_slug}'])">
            {$wa_svg}
            Chamar no WhatsApp
        </a>
    </section>
    </main>

    <!-- ─── LIGHTBOX ─── -->
    <div class="lightbox" id="lightbox" role="dialog" aria-modal="true" aria-label="Imagem ampliada" hidden>
        <button type="button" class="lightbox-close" id="lightbox-close" aria-label="Fechar">&times;</button>
        <button type="button" class="lightbox-nav lightbox-prev" id="lightbox-prev" aria-label="Imagem anterior">&#8249;</button>
        <figure class="lightbox-figure">
            <img class="lightbox-img" id="lightbox-img" src="" alt="">
            <figcaption class="lightbox-caption" id="lightbox-caption"></figcaption>
        </figure>
        <button type="button" class="lightbox-nav lightbox-next" id="lightbox-next" aria-label="Próxima imagem">&#8250;</button>
    </div>

    <!-- ─── FOOTER ─── -->
    <footer class="artist-footer">
        <div class="container">
            <p>&copy; 2026 {$h_display_name} Tattoo &middot; Powered by <a href="/">VAIF</a> &middot; Todos os direitos reservados</p>
        </div>
    </footer>

    <!-- ─── BACK TO TOP ─── -->
    <button class="back-to-top" id="back-to-top" aria-label="Voltar ao topo">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="2.5"><polyline points="18 15 12 9 6 15"/></svg>
    </button>

    <!-- ─── MOBILE STICKY CTA ─── -->
    <div class="mobile-cta-bar">
        <a href="{$h_whatsapp_url}"
           class="btn-primary"
           target="_blank"
           rel="noopener noreferrer"
           onclick="_paq.push(['trackEvent', 'Artista', 'CTA_WhatsApp', '{$h_slug}'])">
            {$wa_svg}
            Agendar via WhatsApp
        </a>
    </div>

    <!-- ─── SCRIPTS ─── -->
    <script src="/artists/artist.js" defer></script>
{$instagram_embed_script}
</body>
</html>
PAGE;
