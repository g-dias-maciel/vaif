<?php

declare(strict_types=1);

// ================================================================
// Routing + Config Loading
// ================================================================

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
// Helpers
// ================================================================

function h(string $str): string
{
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

function image_url(string $img, string $slug): string
{
    if (str_starts_with($img, 'http://') || str_starts_with($img, 'https://') || str_starts_with($img, '//')) {
        return $img;
    }
    return "artists/{$slug}/media/{$img}";
}

$style_list = array_map('trim', preg_split('/[,|]+/', $artist['style'] ?? 'Tatuador'));
$primary_style = $style_list[0];

// ================================================================
// Default Value Computation
// ================================================================

$display_name = $artist['display_name'];
$whatsapp_number = preg_replace('/[^0-9]/', '', $artist['whatsapp_number']);
$whatsapp_text = 'Ola,%20vim%20pelo%20seu%20site%20no%20vaif.com.br';
$whatsapp_url = "https://wa.me/{$whatsapp_number}?text={$whatsapp_text}";
$instagram_handle = $artist['instagram_handle'] ?? '';
$instagram_url = $instagram_handle ? "https://instagram.com/{$instagram_handle}" : '';

$hero_headline = $artist['hero_headline'] ?? "{$display_name} — Tatuador {$primary_style}";
$hero_subheadline = $artist['hero_subheadline'] ?? "{$display_name}, especialista em {$primary_style} em {$artist['location']['city']}. Agende sua sessão pelo WhatsApp.";

$cta_text = $artist['cta_text'] ?? 'Agende sua sessão pelo WhatsApp';

$city = $artist['location']['city'] ?? '';
$title_suffix = $city ? " em {$city}" : '';
$page_title = "{$display_name} — Tatuador {$primary_style}{$title_suffix} | VAIF";

$meta_description = "{$display_name} — Tatuador especialista em {$primary_style}" . ($city ? " em {$city}" : '') . ". Agende sua sessão pelo WhatsApp.";

$og_image = '';
if (!empty($artist['profile_photo'])) {
    $og_image = image_url($artist['profile_photo'], $slug);
} elseif (!empty($artist['portfolio'][0]['src'])) {
    $og_image = image_url($artist['portfolio'][0]['src'], $slug);
}

// ================================================================
// Section Visibility Flags
// ================================================================

$show_hero = true;
$show_portfolio = !empty($artist['portfolio']);
$show_about = !empty($artist['bio']);
$show_testimonials = !empty($artist['testimonials']);
$show_instagram = !empty($artist['instagram_feed']);
$show_faq = true;
$show_location = !empty($artist['location']);
$show_booking = true;

// ================================================================
// FAQ Merging
// ================================================================

$default_faqs = [
    [
        'question' => 'Como funciona o processo de orçamento?',
        'answer'   => 'Você me envia uma mensagem no WhatsApp com sua ideia, referências visuais, tamanho aproximado e região do corpo. Eu analiso e respondo em até 24 horas com valor, número estimado de sessões e disponibilidade de agenda. O orçamento é gratuito e sem compromisso.',
    ],
    [
        'question' => 'Qual o valor médio de uma tatuagem de realismo?',
        'answer'   => 'O valor varia conforme tamanho, complexidade e tempo estimado. Envie sua ideia pelo WhatsApp para receber um orçamento personalizado sem compromisso.',
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
// Helper: Render a single FAQ item
// ================================================================

$faq_items_html = '';
$first = true;
foreach ($merged_faqs as $faq_item) {
    $activeClass = $first ? ' active' : '';
    $expanded = $first ? 'true' : 'false';
    $icon = $first ? '−' : '+';
    $faq_items_html .= <<<HTML
            <div class="faq-item{$activeClass}">
                <button class="faq-question" aria-expanded="{$expanded}">
                    <span>{$faq_item['question']}</span>
                    <span class="faq-icon" aria-hidden="true">{$icon}</span>
                </button>
                <div class="faq-answer">
                    <div class="faq-answer-inner">
                        <p>{$faq_item['answer']}</p>
                    </div>
                </div>
            </div>

HTML;
    $first = false;
}

// ================================================================
// Dynamic Nav Links
// ================================================================

$nav_links_html = '';
$nav_sections = [
    ['id' => 'portfolio',    'label' => 'Portfólio',     'show' => $show_portfolio],
    ['id' => 'about',        'label' => 'Sobre',         'show' => $show_about],
    ['id' => 'testimonials', 'label' => 'Depoimentos',   'show' => $show_testimonials],
    ['id' => 'faq',          'label' => 'FAQ',           'show' => $show_faq],
    ['id' => 'location',     'label' => 'Local',         'show' => $show_location],
];

foreach ($nav_sections as $nav) {
    if ($nav['show']) {
        $nav_links_html .= "                <li><a href=\"#{$nav['id']}\" class=\"nav-link\">{$nav['label']}</a></li>\n";
    }
}

// ================================================================
// JSON-LD
// ================================================================

require_once __DIR__ . '/../components/SeoHelpers.php';

$person_seo = [
    'name' => $display_name,
    'image' => $og_image,
];
if ($instagram_url) {
    $person_seo['sameAs'] = [$instagram_url];
}

$jsonld_html = generatePersonJsonLd($person_seo);

if ($show_location) {
    $biz_seo = [
        'name' => $artist['location']['studio_name'] ?? "{$display_name} Tattoo",
        'url' => 'https://vaif.com.br/artists/' . $slug,
        'image' => $og_image,
        'address' => [
            'street' => $artist['location']['street'] ?? '',
            'city' => $city,
            'state' => $artist['location']['state'] ?? '',
            'zip' => $artist['location']['zip'] ?? '',
        ],
    ];
    $jsonld_html .= "\n" . generateLocalBusinessJsonLd($biz_seo);
}

$jsonld_html .= "\n" . generateFaqPageJsonLd($merged_faqs);

$jsonld_html .= "\n" . generateBreadcrumbListJsonLd([
    ['name' => 'Home', 'url' => 'https://vaif.com.br/'],
    ['name' => 'Artistas', 'url' => 'https://vaif.com.br/artists/'],
    ['name' => $display_name, 'url' => 'https://vaif.com.br/artists/' . $slug],
]);

// ================================================================
// About: speciality tags from style field
// ================================================================
$specialty_tags_html = '';
foreach ($style_list as $style) {
    $hstyle = h(trim($style));
    $specialty_tags_html .= <<<HTML
                    <span class="specialty-tag">{$hstyle}</span>

HTML;
}

// ================================================================
// Portfolio items
// ================================================================
$portfolio_html = '';
foreach ($artist['portfolio'] as $item) {
    $src = h(image_url($item['src'], $slug));
    $alt = h($item['alt'] ?? 'Tatuagem do portfólio');
    $portfolio_html .= <<<HTML
            <div class="portfolio-item">
                <img src="{$src}" alt="{$alt}" loading="lazy">
            </div>

HTML;
}

// ================================================================
// Testimonial cards
// ================================================================
$testimonials_html = '';
foreach ($artist['testimonials'] ?? [] as $t) {
    $stars = '';
    $rating = $t['rating'] ?? 5;
    for ($i = 0; $i < $rating; $i++) {
        $stars .= '★';
    }

    $name = h($t['name'] ?? 'Cliente');
    $initials = '';
    $words = explode(' ', $name);
    foreach ($words as $w) {
        $initials .= mb_substr($w, 0, 1);
    }
    $initials = mb_strtoupper(mb_substr($initials, 0, 2));
    $meta = h($t['meta'] ?? 'Cliente');
    $text = h($t['text'] ?? '');

    $avatar_html = '';
    if (!empty($t['photo'])) {
        $photo_url = h(image_url($t['photo'], $slug));
        $avatar_html = "<img src=\"{$photo_url}\" alt=\"{$name}\" class=\"testimonial-avatar-img\" style=\"width:36px;height:36px;border-radius:50%;object-fit:cover;\">";
    } else {
        $avatar_html = "<div class=\"testimonial-avatar\" aria-hidden=\"true\">{$initials}</div>";
    }

    $testimonials_html .= <<<HTML
            <div class="testimonial-card">
                <div class="testimonial-stars" aria-label="{$rating} de 5 estrelas">{$stars}</div>
                <p class="testimonial-text">"{$text}"</p>
                <div class="testimonial-author">
                    {$avatar_html}
                    <div>
                        <div class="testimonial-name">{$name}</div>
                        <div class="testimonial-meta">{$meta}</div>
                    </div>
                </div>
            </div>

HTML;
}

// ================================================================
// Instagram feed placeholder items
// ================================================================
$instagram_grid_html = '';
for ($i = 1; $i <= 8; $i++) {
    $instagram_grid_html .= <<<HTML
            <div class="instagram-item">
                <img src="https://placehold.co/300x300/1a1a1a/D4B04C?text=Post+{$i}&font=montserrat" alt="Instagram post {$i}" loading="lazy">
            </div>

HTML;
}

// ================================================================
// Location section HTML
// ================================================================
$location_html = '';
if ($show_location) {
    $loc = $artist['location'];
    $studio_name = h($loc['studio_name'] ?? "{$display_name} Tattoo");
    $street = h($loc['street'] ?? '');
    $neighborhood = h($loc['neighborhood'] ?? '');
    $city_state = h(($loc['city'] ?? '') . ' — ' . ($loc['state'] ?? ''));
    $zip = h($loc['zip'] ?? '');
    $maps_url = h($loc['maps_embed_url'] ?? '');

    $address_html = "{$street}<br>\n                    {$neighborhood}, {$city_state}<br>\n                    CEP: {$zip}";

    $location_html = <<<HTML
    <section id="location" class="artist-section">
        <div class="container">
            <div class="section-header">
                <span class="section-tag">Localização</span>
                <h2 class="section-heading">Onde <span>me encontrar</span></h2>
                <div class="diamond-divider">
                    <span class="line"></span><span class="diamond"></span><span class="line"></span>
                </div>
            </div>
        </div>
        <div class="location-grid">
            <div class="location-details">
                <h3>{$studio_name}</h3>
                <address>
                    {$address_html}
                </address>
                <div class="diamond-divider">
                    <span class="line"></span><span class="diamond"></span><span class="line"></span>
                </div>
                <div class="location-info">
                    <div class="location-info-item">
                        <svg viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        <span>Seg a Sáb: 10h às 19h (com hora marcada)</span>
                    </div>
                    <div class="location-info-item">
                        <svg viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2" aria-hidden="true"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                        <span>Atendimento exclusivo com horário agendado</span>
                    </div>
                </div>
            </div>
            <div class="map-frame">
                <iframe
                    title="Mapa do estúdio {$studio_name}"
                    src="{$maps_url}"
                    allowfullscreen=""
                    loading="lazy"
                    referrerpolicy="no-referrer-when-downgrade">
                </iframe>
            </div>
        </div>
    </section>

HTML;
}

// ================================================================
// Render 404 page
// ================================================================

function render_404(): void
{
    echo <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Artista não encontrado — VAIF</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,600;0,700;1,400&family=Montserrat:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            background: #0A0A0A;
            color: rgb(242, 237, 228);
            font-family: 'Montserrat', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 24px;
            background-image: radial-gradient(circle at 80% 20%, rgba(212, 176, 76, 0.05), transparent 40%);
        }
        .err-card {
            background: #121212;
            border: 1px solid #222222;
            border-radius: 16px;
            padding: 60px 40px;
            max-width: 480px;
            width: 100%;
        }
        h1 {
            font-family: 'Cormorant Garamond', serif;
            font-size: 2.5rem;
            font-weight: 600;
            color: #D4B04C;
            margin-bottom: 16px;
        }
        p {
            color: #CCCCCC;
            font-size: 1rem;
            line-height: 1.7;
            margin-bottom: 32px;
        }
        .btn-back {
            display: inline-block;
            padding: 14px 32px;
            background: #D4B04C;
            color: #000;
            text-decoration: none;
            font-family: 'Montserrat', sans-serif;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 2px;
            text-transform: uppercase;
            border-radius: 4px;
            transition: all 0.3s;
        }
        .btn-back:hover {
            background: #E5C35E;
            transform: translateY(-2px);
        }
    </style>
</head>
<body>
    <div class="err-card">
        <h1>Artista não encontrado</h1>
        <p>A página que você procura não existe ou o artista ainda não foi cadastrado em nossa plataforma.</p>
        <a href="/" class="btn-back">Voltar ao site</a>
    </div>
</body>
</html>
HTML;
}

// ================================================================
// Helper: image path detection for section rendering
// ================================================================

$hero_img_url = image_url($artist['profile_photo'], $slug);
$about_img_url = image_url($artist['profile_photo'], $slug);

// ================================================================
// WhatsApp SVG icon (reused)
// ================================================================
$wa_svg = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>';

// ================================================================
// Conditional section HTML strings
// ================================================================

$about_html = '';
if ($show_about) {
    $bio_content = $artist['bio'];
    $about_html = <<<HTML
    <!-- ═══ SECTION 3: ABOUT ═══ -->
    <section id="about" class="artist-section">
        <div class="container">
            <div class="section-header">
                <span class="section-tag">Sobre o Artista</span>
                <h2 class="section-heading">Conheça <span>{$display_name}</span></h2>
                <div class="diamond-divider">
                    <span class="line"></span><span class="diamond"></span><span class="line"></span>
                </div>
            </div>
        </div>
        <div class="about-grid">
            <img
                src="{$about_img_url}"
                alt="{$display_name} no estúdio"
                class="about-photo"
                loading="lazy"
            >
            <div class="about-text">
                {$bio_content}
                <div class="about-specialties">
{$specialty_tags_html}                </div>
                <div class="about-stats">
                    <div class="about-stat">
                        <strong>8+</strong>
                        <span>Anos de Experiência</span>
                    </div>
                    <div class="about-stat">
                        <strong>600+</strong>
                        <span>Tatuagens Realizadas</span>
                    </div>
                    <div class="about-stat">
                        <strong>4.9</strong>
                        <span>Avaliação Média</span>
                    </div>
                </div>
            </div>
        </div>
    </section>

HTML;
}

$portfolio_section_html = '';
if ($show_portfolio) {
    $portfolio_section_html = <<<HTML
    <!-- ═══ SECTION 2: PORTFOLIO ═══ -->
    <section id="portfolio" class="artist-section">
        <div class="container">
            <div class="section-header">
                <span class="section-tag">Portfólio</span>
                <h2 class="section-heading">Trabalhos <span>recentes</span></h2>
                <div class="tattoo-divider">
                    <span class="dot"></span>
                    <span class="needle"></span>
                    <span class="dot"></span>
                    <span class="line"></span>
                    <span class="diamond" style="width:6px;height:6px;background:var(--gold);transform:rotate(45deg);flex-shrink:0;"></span>
                    <span class="line"></span>
                    <span class="dot"></span>
                    <span class="needle"></span>
                    <span class="dot"></span>
                </div>
            </div>
        </div>
        <div class="portfolio-grid">
{$portfolio_html}        </div>
    </section>

HTML;
}

$testimonials_section_html = '';
if ($show_testimonials) {
    $testimonials_section_html = <<<HTML
    <!-- ═══ SECTION 5: TESTIMONIALS ═══ -->
    <section id="testimonials" class="artist-section">
        <div class="container">
            <div class="section-header">
                <span class="section-tag">Depoimentos</span>
                <h2 class="section-heading">O que meus <span>clientes dizem</span></h2>
                <div class="diamond-divider">
                    <span class="line"></span><span class="diamond"></span><span class="line"></span>
                </div>
            </div>
        </div>
        <div class="testimonials-grid">
{$testimonials_html}        </div>
    </section>

HTML;
}

$instagram_section_html = '';
if ($show_instagram) {
    $ig_handle_h = h($instagram_handle);
    $ig_url_h = h($instagram_url);
    $instagram_section_html = <<<HTML
    <!-- ═══ SECTION 6: INSTAGRAM FEED ═══ -->
    <section id="instagram" class="artist-section">
        <div class="container">
            <div class="section-header">
                <span class="section-tag">Instagram</span>
                <h2 class="section-heading">Acompanhe o <span>dia a dia</span></h2>
                <div class="diamond-divider">
                    <span class="line"></span><span class="diamond"></span><span class="line"></span>
                </div>
            </div>
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
    <style>
        :root {
            --gold: #D4B04C;
            --bg-dark: #0A0A0A;
            --bg-card: #121212;
            --text-main: rgb(242, 237, 228);
            --text-muted: #CCCCCC;
            --border-color: #222222;
            --gold-light: rgba(212, 176, 76, 0.1);
            --gold-dark: rgba(212, 176, 76, 0.25);
            --space-xs: 8px;
            --space-sm: 16px;
            --space-md: 24px;
            --space-lg: 40px;
            --space-xl: 60px;
            --space-2xl: 80px;
            --radius-sm: 4px;
            --radius-md: 8px;
            --radius-lg: 16px;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        @media (prefers-reduced-motion: no-preference) {
            * { scroll-behavior: smooth; }
        }

        body {
            background-color: var(--bg-dark);
            color: var(--text-main);
            font-family: 'Montserrat', sans-serif;
            line-height: 1.6;
            overflow-x: hidden;
            background-image: radial-gradient(circle at 80% 20%, rgba(212, 176, 76, 0.05), transparent 40%);
        }

        h1, h2, h3, .serif-font {
            font-family: 'Cormorant Garamond', serif;
            font-weight: 600;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 24px;
        }

        /* ─── FOCUS VISIBLE ─── */
        a:focus-visible,
        button:focus-visible {
            outline: 2px solid var(--gold);
            outline-offset: 2px;
            border-radius: 2px;
        }
        .nav-hamburger:focus-visible { outline-offset: 0; }

        /* ─── SECTION LAYOUT ─── */
        .artist-section {
            padding: 5rem 2rem;
            position: relative;
        }
        .artist-section:nth-child(even) {
            background: #0d0d0d;
        }
        .section-header {
            text-align: center;
            margin-bottom: var(--space-xl);
        }
        .section-tag {
            display: inline-block;
            font-family: 'Montserrat', sans-serif;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 3px;
            text-transform: uppercase;
            color: var(--gold);
            margin-bottom: var(--space-sm);
        }
        .section-heading {
            font-family: 'Cormorant Garamond', serif;
            font-size: clamp(2rem, 4vw, 3rem);
            font-weight: 700;
            line-height: 1.15;
            margin-bottom: var(--space-md);
        }
        .section-heading span { color: var(--gold); font-style: italic; }
        .diamond-divider {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            margin: var(--space-md) 0;
        }
        .diamond-divider .line { width: 40px; height: 1px; background: var(--border-color); }
        .diamond-divider .diamond {
            width: 6px; height: 6px;
            background: var(--gold);
            transform: rotate(45deg);
            flex-shrink: 0;
        }

        /* ─── NAV ─── */
        .navbar {
            position: fixed;
            top: 0; left: 0; right: 0;
            z-index: 1000;
            background: rgba(10, 10, 10, 0.9);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--border-color);
            padding: 12px 0;
        }
        .navbar .container {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .nav-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
        }
        .nav-logo {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.6rem;
            font-weight: 700;
            color: var(--gold);
            letter-spacing: 2px;
        }
        .nav-tagline {
            font-size: 9px;
            color: var(--text-muted);
            letter-spacing: 2px;
            text-transform: uppercase;
            border-left: 1px solid var(--border-color);
            padding-left: 10px;
        }
        .nav-links {
            display: flex;
            align-items: center;
            gap: 20px;
            list-style: none;
        }
        .nav-link {
            color: var(--text-muted);
            text-decoration: none;
            font-size: 11px;
            font-weight: 500;
            letter-spacing: 1px;
            text-transform: uppercase;
            transition: color 0.3s;
        }
        .nav-link:hover { color: var(--gold); }
        .nav-btn-highlight {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            background: var(--gold);
            color: #000;
            font-family: 'Montserrat', sans-serif;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 2px;
            text-transform: uppercase;
            text-decoration: none;
            border-radius: var(--radius-sm);
            transition: all 0.3s;
        }
        .nav-btn-highlight:hover {
            background: #E5C35E;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.4);
        }

        /* ─── HAMBURGER ─── */
        .nav-hamburger {
            display: none;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            gap: 5px;
            width: 44px; height: 44px;
            background: none;
            border: none;
            cursor: pointer;
            padding: 0;
            z-index: 1001;
        }
        .nav-hamburger span {
            display: block;
            width: 22px; height: 2px;
            background: var(--text-main);
            border-radius: 2px;
            transition: all 0.3s;
        }
        .nav-hamburger.active span:nth-child(1) { transform: translateY(7px) rotate(45deg); }
        .nav-hamburger.active span:nth-child(2) { opacity: 0; transform: scaleX(0); }
        .nav-hamburger.active span:nth-child(3) { transform: translateY(-7px) rotate(-45deg); }

        /* ─── SECTION 1: HERO ─── */
        #hero {
            position: relative;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 100px 2rem 4rem;
            overflow: hidden;
            text-align: center;
        }
        .hero-bg {
            position: absolute;
            inset: 0;
            z-index: 0;
        }
        .hero-bg img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: center 15%;
            filter: brightness(0.55) contrast(1.05) saturate(0.45);
        }
        .hero-bg::after {
            content: '';
            position: absolute;
            inset: 0;
            background:
                radial-gradient(ellipse 80% 60% at 50% 45%, transparent 0%, var(--bg-dark) 95%);
        }
        .hero-content-wrapper {
            position: relative;
            z-index: 1;
            max-width: 700px;
        }
        .hero-eyebrow {
            display: block;
            color: var(--gold);
            font-family: 'Montserrat', sans-serif;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 3px;
            text-transform: uppercase;
            margin-bottom: 1rem;
        }
        .hero-title {
            font-size: clamp(2.8rem, 6vw, 4.5rem);
            font-weight: 700;
            line-height: 1.08;
            margin-bottom: 1.5rem;
            letter-spacing: -1px;
        }
        .hero-title span { color: var(--gold); font-style: italic; }
        .hero-subtitle {
            font-size: 1.1rem;
            color: var(--text-muted);
            margin-bottom: 2rem;
            line-height: 1.7;
        }
        .hero-ctas {
            display: flex;
            justify-content: center;
            gap: 1rem;
            flex-wrap: wrap;
        }
        .btn-primary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 16px 32px;
            background: var(--gold);
            color: #000;
            font-family: 'Montserrat', sans-serif;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 2px;
            text-transform: uppercase;
            text-decoration: none;
            border: none;
            cursor: pointer;
            border-radius: var(--radius-sm);
            transition: all 0.3s;
        }
        .btn-primary:hover {
            background: #E5C35E;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.4);
        }
        .btn-secondary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 16px 32px;
            background: transparent;
            border: 2px solid var(--gold);
            color: var(--gold);
            font-family: 'Montserrat', sans-serif;
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 2px;
            text-transform: uppercase;
            text-decoration: none;
            cursor: pointer;
            border-radius: var(--radius-sm);
            transition: all 0.3s;
        }
        .btn-secondary:hover {
            border-color: var(--gold);
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(0,0,0,0.4);
        }

        /* ─── SECTION 2: PORTFOLIO ─── */
        .portfolio-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: var(--space-sm);
            max-width: 1200px;
            margin: 0 auto;
        }
        .portfolio-item {
            position: relative;
            aspect-ratio: 1;
            overflow: hidden;
            border-radius: var(--radius-md);
        }
        .portfolio-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        /* ─── SECTION 3: ABOUT ─── */
        .about-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: var(--space-xl);
            max-width: 1000px;
            margin: 0 auto;
            align-items: center;
        }
        .about-photo {
            width: 100%;
            max-width: 380px;
            aspect-ratio: 4/5;
            object-fit: cover;
            border: 1px solid var(--gold-dark);
            border-radius: var(--radius-md);
            justify-self: center;
        }
        .about-text p {
            font-size: 1.05rem;
            color: #E0E0E0;
            line-height: 1.8;
            margin-bottom: var(--space-md);
        }
        .about-specialties {
            display: flex;
            flex-wrap: wrap;
            gap: var(--space-xs);
            margin-top: var(--space-md);
        }
        .specialty-tag {
            display: inline-block;
            padding: 6px 14px;
            border: 1px solid var(--gold-dark);
            border-radius: 20px;
            font-size: 11px;
            color: var(--gold);
            font-weight: 500;
            letter-spacing: 1px;
        }
        .about-stats {
            display: flex;
            gap: var(--space-lg);
            margin-top: var(--space-lg);
            padding-top: var(--space-md);
            border-top: 1px solid var(--border-color);
        }
        .about-stat strong {
            color: var(--gold);
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.8rem;
            font-weight: 700;
            display: block;
            line-height: 1;
        }
        .about-stat span {
            font-size: 10px;
            color: var(--text-muted);
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        /* ─── SECTION 4: BOOKING CTA ─── */
        #booking {
            background: linear-gradient(180deg, #0d0d0d 0%, var(--bg-card) 50%, #0d0d0d 100%);
            border-top: 1px solid var(--border-color);
            border-bottom: 1px solid var(--border-color);
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        #booking::before {
            content: '';
            position: absolute;
            top: -50%; left: 50%;
            transform: translateX(-50%);
            width: 600px; height: 600px;
            background: radial-gradient(circle, rgba(212, 176, 76, 0.08) 0%, transparent 70%);
            pointer-events: none;
        }
        .booking-box {
            max-width: 650px;
            margin: 0 auto;
            padding: 50px 40px;
            background: rgba(10, 10, 10, 0.8);
            border: 1px solid rgba(212, 176, 76, 0.25);
            border-radius: var(--radius-lg);
            position: relative;
            box-shadow: 0 20px 60px rgba(0,0,0,0.5);
        }
        .booking-label {
            display: inline-block;
            padding: 6px 16px;
            background: rgba(212, 176, 76, 0.1);
            border: 1px solid var(--gold);
            color: var(--gold);
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 2px;
            text-transform: uppercase;
            margin-bottom: 20px;
            border-radius: 20px;
        }
        .booking-box h2 {
            font-size: clamp(2rem, 3.5vw, 2.8rem);
            margin-bottom: 15px;
        }
        .booking-box h2 span { color: var(--gold); font-style: italic; }
        .booking-box p {
            color: var(--text-muted);
            font-size: 1.05rem;
            line-height: 1.7;
            margin-bottom: 30px;
            max-width: 500px;
            margin-left: auto;
            margin-right: auto;
        }
        .whatsapp-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 18px 40px;
            background: var(--gold);
            color: #000;
            font-family: 'Montserrat', sans-serif;
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 2px;
            text-transform: uppercase;
            text-decoration: none;
            border-radius: var(--radius-sm);
            transition: all 0.3s;
            box-shadow: 0 4px 20px rgba(0,0,0,0.4);
        }
        .whatsapp-btn:hover {
            background: #E5C35E;
            transform: translateY(-3px);
            box-shadow: 0 8px 30px rgba(0,0,0,0.5);
        }
        .whatsapp-btn svg { width: 22px; height: 22px; }

        /* ─── SECTION 5: TESTIMONIALS ─── */
        .testimonials-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: var(--space-lg);
            max-width: 1200px;
            margin: 0 auto;
        }
        .testimonial-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            padding: var(--space-lg);
            border-radius: var(--radius-md);
            transition: all 0.3s;
        }
        .testimonial-card:hover {
            border-color: rgba(212, 176, 76, 0.3);
            transform: translateY(-3px);
        }
        .testimonial-stars {
            color: var(--gold);
            font-size: 14px;
            letter-spacing: 2px;
            margin-bottom: var(--space-sm);
        }
        .testimonial-text {
            font-size: 0.95rem;
            color: #E0E0E0;
            line-height: 1.7;
            margin-bottom: var(--space-md);
            font-style: italic;
        }
        .testimonial-author {
            display: flex;
            align-items: center;
            gap: 10px;
            border-top: 1px solid var(--border-color);
            padding-top: var(--space-sm);
        }
        .testimonial-avatar {
            width: 36px; height: 36px;
            border-radius: 50%;
            background: var(--bg-dark);
            border: 1px solid var(--gold-dark);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 600;
            color: var(--gold);
        }
        .testimonial-name {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-main);
        }
        .testimonial-meta {
            font-size: 10px;
            color: var(--text-muted);
        }

        /* ─── SECTION 6: INSTAGRAM ─── */
        .instagram-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: var(--space-xs);
            max-width: 1200px;
            margin: 0 auto;
        }
        .instagram-item {
            aspect-ratio: 1;
            overflow: hidden;
            border-radius: var(--radius-md);
            position: relative;
            border: 2px solid var(--border-color);
            transition: border-color 0.3s;
        }
        .instagram-item:hover { border-color: var(--gold); }
        .instagram-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s;
        }
        .instagram-item:hover img { transform: scale(1.05); }
        .instagram-handle {
            text-align: center;
            margin-top: var(--space-lg);
        }
        .instagram-handle a {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--gold);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            letter-spacing: 1px;
            transition: color 0.3s;
        }
        .instagram-handle a:hover { color: #E5C35E; }
        .instagram-followers {
            text-align: center;
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 4px;
        }

        /* ─── SECTION 7: FAQ ─── */
        .faq-list {
            max-width: 750px;
            margin: 0 auto;
        }
        .faq-item {
            border-bottom: 1px solid var(--border-color);
        }
        .faq-question {
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
            padding: var(--space-md) 0;
            background: none;
            border: none;
            color: var(--text-main);
            font-family: 'Montserrat', sans-serif;
            font-size: 1rem;
            font-weight: 500;
            text-align: left;
            cursor: pointer;
            transition: color 0.3s;
            gap: var(--space-sm);
        }
        .faq-question:hover { color: var(--gold); }
        .faq-icon {
            flex-shrink: 0;
            width: 24px; height: 24px;
            border: 1px solid var(--gold-dark);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s;
            color: var(--gold);
            font-size: 16px;
            line-height: 1;
        }
        .faq-item.active .faq-icon {
            background: var(--gold);
            color: #000;
            border-color: var(--gold);
        }
        .faq-answer {
            display: grid;
            grid-template-rows: 0fr;
            transition: grid-template-rows 0.35s ease;
        }
        .faq-answer-inner {
            overflow: hidden;
        }
        .faq-answer-inner p {
            font-size: 0.95rem;
            color: var(--text-muted);
            line-height: 1.7;
            padding-bottom: var(--space-md);
        }
        .faq-item.active .faq-answer {
            grid-template-rows: 1fr;
        }

        /* ─── SECTION 8: LOCATION ─── */
        .location-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: var(--space-xl);
            max-width: 1000px;
            margin: 0 auto;
            align-items: start;
        }
        .location-details {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            padding: var(--space-xl);
            border-radius: var(--radius-md);
        }
        .location-details h3 {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.4rem;
            margin-bottom: var(--space-sm);
            color: var(--gold);
        }
        .location-details address {
            font-style: normal;
            color: var(--text-muted);
            line-height: 1.8;
            margin-bottom: var(--space-md);
        }
        .location-details .location-info {
            display: flex;
            flex-direction: column;
            gap: var(--space-sm);
        }
        .location-info-item {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 13px;
            color: var(--text-muted);
        }
        .location-info-item svg {
            width: 16px; height: 16px;
            flex-shrink: 0;
            color: var(--gold);
        }
        .map-frame {
            width: 100%;
            aspect-ratio: 16/12;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            overflow: hidden;
        }
        .map-frame iframe {
            width: 100%;
            height: 100%;
            border: 0;
            filter: grayscale(85%) contrast(0.9) brightness(0.7);
        }

        /* ─── DIVAIDER TATTOO MOTIF ─── */
        .tattoo-divider {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            margin: var(--space-md) 0;
        }
        .tattoo-divider .dot {
            width: 3px; height: 3px;
            border-radius: 50%;
            background: var(--gold);
            opacity: 0.5;
        }
        .tattoo-divider .line {
            width: 60px; height: 1px;
            background: var(--border-color);
        }
        .tattoo-divider .needle {
            width: 18px; height: 1px;
            background: var(--gold);
            opacity: 0.4;
            position: relative;
        }
        .tattoo-divider .needle::after {
            content: '';
            position: absolute;
            right: -1px; top: -1.5px;
            width: 4px; height: 4px;
            border-radius: 50%;
            background: var(--gold);
            opacity: 0.7;
        }

        /* ─── FOOTER ─── */
        .artist-footer {
            text-align: center;
            padding: var(--space-xl) 0;
            border-top: 1px solid var(--border-color);
            color: var(--text-muted);
            font-size: 12px;
        }
        .artist-footer a {
            color: var(--gold);
            text-decoration: none;
        }

        /* ─── CLOSING CTA ─── */
        .closing-cta {
            background: linear-gradient(180deg, #0d0d0d 0%, var(--bg-card) 50%, #0d0d0d 100%);
            border-top: 1px solid var(--border-color);
            border-bottom: 1px solid var(--border-color);
            text-align: center;
            padding: var(--space-xl) 1.5rem;
            position: relative;
            overflow: hidden;
        }
        .closing-cta::before {
            content: '';
            position: absolute;
            top: -50%; left: 50%;
            transform: translateX(-50%);
            width: 500px; height: 500px;
            background: radial-gradient(circle, rgba(212, 176, 76, 0.06) 0%, transparent 70%);
            pointer-events: none;
        }
        .closing-cta h2 {
            position: relative;
        }
        .closing-cta p {
            position: relative;
            color: var(--text-muted);
            font-size: 1rem;
            margin-bottom: var(--space-md);
        }
        .closing-cta .whatsapp-btn {
            position: relative;
        }

        /* ─── SKIP TO CONTENT ─── */
        .skip-link {
            position: absolute;
            top: -100px;
            left: 16px;
            padding: 12px 20px;
            background: var(--gold);
            color: #000;
            font-family: 'Montserrat', sans-serif;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
            text-decoration: none;
            border-radius: var(--radius-sm);
            z-index: 2000;
            transition: top 0.2s;
        }
        .skip-link:focus {
            top: 16px;
        }

        /* ─── BACK TO TOP ─── */
        .back-to-top {
            position: fixed;
            bottom: 24px;
            right: 24px;
            width: 40px; height: 40px;
            background: var(--bg-card);
            border: 1px solid var(--gold-dark);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            z-index: 900;
            opacity: 0;
            visibility: hidden;
            transform: translateY(10px);
            transition: all 0.3s;
        }
        .back-to-top.visible {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }
        .back-to-top svg {
            width: 16px; height: 16px;
            stroke: var(--gold);
        }

        /* ─── STICKY MOBILE CTA ─── */
        .mobile-cta-bar {
            display: none;
            position: fixed;
            bottom: 0; left: 0; right: 0;
            padding: 10px 16px;
            background: rgba(10, 10, 10, 0.95);
            backdrop-filter: blur(12px);
            border-top: 1px solid var(--border-color);
            z-index: 998;
        }
        .mobile-cta-bar .btn-primary {
            width: 100%;
            padding: 14px 24px;
        }

        /* ─── RESPONSIVE ─── */
        @media (max-width: 768px) {
            .nav-hamburger { display: flex; }
            .nav-tagline { display: none; }
            .nav-links {
                position: fixed;
                inset: 0;
                z-index: 999;
                flex-direction: column;
                justify-content: center;
                align-items: center;
                gap: 32px;
                background: rgba(10, 10, 10, 0.98);
                backdrop-filter: blur(20px);
                opacity: 0;
                visibility: hidden;
                transform: translateY(-10px);
                transition: opacity 0.35s, visibility 0.35s, transform 0.35s;
            }
            .nav-links.open { opacity: 1; visibility: visible; transform: translateY(0); }
            .nav-links .nav-link { font-size: 15px; color: var(--text-main); }
            .nav-links .nav-btn-highlight { font-size: 13px; padding: 14px 32px; margin-top: 8px; }

            .artist-section { padding: 3.5rem 1.25rem; }

            .hero-bg img { object-position: center 10%; }
            .hero-title { font-size: 2.2rem; }
            .hero-ctas { flex-direction: column; align-items: center; }
            .hero-ctas .btn-primary { width: 100%; text-align: center; }

            .portfolio-grid { grid-template-columns: repeat(2, 1fr); }
            .about-grid { grid-template-columns: 1fr; gap: var(--space-lg); text-align: center; }
            .about-specialties { justify-content: center; }
            .about-stats { justify-content: center; gap: var(--space-md); }
            .about-stat strong { font-size: 1.4rem; }
            .testimonials-grid { grid-template-columns: 1fr; }
            .instagram-grid { grid-template-columns: repeat(3, 1fr); }
            .location-grid { grid-template-columns: 1fr; }
            .booking-box { padding: 30px 20px; }
            .specialty-tag { padding: 8px 16px; font-size: 12px; }

            .mobile-cta-bar { display: block; }
            .back-to-top { bottom: 80px; }
        }

        @media (max-width: 480px) {
            .portfolio-grid { grid-template-columns: 1fr 1fr; gap: 6px; }
            .instagram-grid { grid-template-columns: repeat(3, 1fr); gap: 6px; }
            .faq-question { font-size: 0.9rem; }
        }
    </style>
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
            <img src="{$hero_img_url}" alt="" aria-hidden="true" loading="eager">
        </div>
        <div class="hero-content-wrapper">
            <span class="hero-eyebrow">Tatuador Especialista em {$h_primary_style}</span>
            <h1 class="hero-title">{$display_name}</h1>
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

{$portfolio_section_html}{$about_html}
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
    <script>
        (function() {
            const hamburger = document.getElementById('nav-hamburger');
            const navLinks = document.getElementById('nav-links');

            function closeNav() {
                navLinks.classList.remove('open');
                hamburger.classList.remove('active');
                hamburger.setAttribute('aria-expanded', 'false');
                hamburger.focus();
            }

            function openNav() {
                navLinks.classList.add('open');
                hamburger.classList.add('active');
                hamburger.setAttribute('aria-expanded', 'true');
            }

            hamburger.addEventListener('click', function() {
                if (navLinks.classList.contains('open')) {
                    closeNav();
                } else {
                    openNav();
                }
            });

            navLinks.addEventListener('click', function(e) {
                if (e.target === navLinks) {
                    closeNav();
                }
            });

            navLinks.querySelectorAll('a').forEach(function(link) {
                link.addEventListener('click', function() {
                    closeNav();
                });
            });

            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && navLinks.classList.contains('open')) {
                    closeNav();
                }
            });

            const faqItems = document.querySelectorAll('.faq-item');
            faqItems.forEach(function(item) {
                const btn = item.querySelector('.faq-question');
                const icon = item.querySelector('.faq-icon');
                btn.addEventListener('click', function() {
                    const isActive = item.classList.contains('active');
                    item.classList.toggle('active');
                    icon.textContent = isActive ? '+' : '\u2212';
                    btn.setAttribute('aria-expanded', isActive ? 'false' : 'true');
                });
            });

            const backToTop = document.getElementById('back-to-top');
            window.addEventListener('scroll', function() {
                if (window.scrollY > 600) {
                    backToTop.classList.add('visible');
                } else {
                    backToTop.classList.remove('visible');
                }
            });
            backToTop.addEventListener('click', function() {
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
        })();
    </script>
</body>
</html>
PAGE;
