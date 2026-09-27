<?php
/**
 * VAIF LP — Automated Acceptance Tests
 *
 * Tests: CTA links, branding text, emoji removal, service card structure, form fields.
 * Run: php tests/acceptance_test.php
 */

$BASE = 'http://localhost:8000';

$passed = 0;
$failed = 0;

function test(string $label, bool $condition, string $detail = '') {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  ✅ PASS: {$label}\n";
    } else {
        $failed++;
        $msg = $detail ? " — {$detail}" : '';
        echo "  ❌ FAIL: {$label}{$msg}\n";
    }
}

function fetch(string $url): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $html = curl_exec($ch);
    curl_close($ch);
    return $html;
}

// ── 1. Fetch pages ──────────────────────────────────────
echo "\n=== Fetching pages ===\n";
$index = fetch("{$BASE}/index.php");
$calc  = fetch("{$BASE}/calculadora.php");
$calc2 = fetch("{$BASE}/calculadora-v2.php");

test('Index page loads', strlen($index) > 1000, 'Content length: ' . strlen($index));
test('Calculadora page loads', strlen($calc) > 1000, 'Content length: ' . strlen($calc));

// ── 2. "SDR de IA" → "Atendente Virtual" rebrand ─────────
echo "\n=== Branding: SDR de IA → Atendente Virtual ===\n";
test('Nav does NOT contain "SDR de IA"', !str_contains($index, 'SDR de IA'),
    'Found "SDR de IA" in page');
test('Nav contains "Atendente Virtual" in nav', str_contains($index, 'Atendente Virtual'),
    'Missing "Atendente Virtual" in nav');
test('Nav link uses "Atendente Virtual" not "SDR de IA"',
    !str_contains($index, 'SDR de IA') && str_contains($index, 'Atendente Virtual'),
    '"SDR de IA" still present or "Atendente Virtual" missing');

// Hero subtitle
test('Hero subtitle mentions "Recepcionista de IA"',
    str_contains($index, 'Recepcionista de IA qualificando um cliente de alto padrão'),
    'Hero subtitle text not updated');

// Value card #2 description
test('Value card description uses "atendente virtual especializado"',
    str_contains($index, 'Um atendente virtual especializado treinado'),
    'Value card #2 text not updated');

// Chat section heading
test('Chat heading uses "atendente virtual"',
    str_contains($index, 'Nosso atendente virtual'),
    'Chat section heading not updated');

// Service card #2 title
test('Service card 02 uses "Atendente Virtual + CRM"',
    str_contains($index, 'Atendente Virtual + CRM'),
    'Service card #2 title not updated');

// Footer
test('Footer nav uses "Atendente Virtual"',
    substr_count($index, 'Atendente Virtual') >= 2,
    'Atendente Virtual should appear at least twice (nav + footer)');

// chat.js
$chatJs = file_get_contents(__DIR__ . '/../js/chat.js');
test('chat.js does NOT reference "SDR de IA"', !str_contains($chatJs, 'SDR de IA'),
    'Found "SDR de IA" in chat.js');
test('chat.js references "Atendente Virtual"', str_contains($chatJs, 'Atendente Virtual'),
    'Missing "Atendente Virtual" in chat.js');

// ── 3. 🧮 emoji removed from calculator buttons ────────
echo "\n=== Emoji removal ===\n";
test('Nav calculator button has no 🧮 emoji',
    !str_contains($index, '🧮') && str_contains($index, 'Calculadora de Lucro'),
    '🧮 emoji found on page or calculator button missing');
test('Teaser CTA has no 🧮 emoji',
    !str_contains($index, '🧮') || (strpos($index, '🧮') === false || !str_contains(substr($index, (int)strpos($index, 'btn-gold-large'), 200), '🧮')),
    'Found 🧮 in teaser CTA area');

// Simpler check: the teaser section should NOT contain 🧮 at all
$teaserPos = strpos($index, 'calc-teaser-section');
if ($teaserPos !== false) {
    $teaserSection = substr($index, $teaserPos, 1000);
    test('Teaser section has no 🧮 emoji', !str_contains($teaserSection, '🧮'),
        '🧮 still present in teaser section');
}

// ── 4. All CTAs point to #aplicar ─────────────────────
echo "\n=== CTA validation ===\n";
// Count all distinct CTA/link hrefs that go to aplicacao
preg_match_all('/href=["\']([^"\']+)["\']/', $index, $links);
$homeLinks = $links[1];

$ctasPointingToForm = 0;
$ctasOffSite = 0;
foreach ($homeLinks as $href) {
    if ($href === '#aplicar') $ctasPointingToForm++;
    if (str_starts_with($href, 'http') || str_starts_with($href, '//')) $ctasOffSite++;
}

// Expected: nav "Aplicar", hero button, all 6 service "Saber Mais", chat lead form submit
test('At least 8 CTAs point to #aplicar', $ctasPointingToForm >= 8,
    "Found {$ctasPointingToForm} CTAs pointing to #aplicar (expected ≥8)");

// Hero CTA
test('Hero CTA points to #aplicar',
    (bool)preg_match('/<a\s[^>]*href="#aplicar"[^>]*class="btn-primary"[^>]*>/', $index),
    'Hero primary button href is not #aplicar');

// ── 5. Service cards: number before title (icons removed) ────
echo "\n=== Service card structure ===\n";
$cards = explode('service-card', $index);
$cardCount = count($cards) - 1; // first element is before first card
test('6 service cards found', $cardCount === 6, "Found {$cardCount} cards");

if ($cardCount >= 1) {
    // Check card 1 structure — number before title, icons were removed by design
    $card1 = $cards[1]; // first actual card
    $posNum   = strpos($card1, 'service-number');
    $posH3    = strpos($card1, '<h3');
    $posIcon  = strpos($card1, 'service-icon');
    test('Card 01: number before title', $posNum !== false && $posH3 !== false && $posNum < $posH3,
        'Number should come before h3');
    // Icons intentionally removed — check they're gone
    test('Card 01: no service-icon (design decision)', $posIcon === false,
        'service-icon still present — should have been removed');
}

// ── 6. Qualifying form fields ─────────────────────────
echo "\n=== Form fields ===\n";
test('Qualification form exists', str_contains($index, 'id="qualification-form"'),
    'Missing qualification form');
test('Form has name field', str_contains($index, 'name="f-name"'));
test('Form has studio field', str_contains($index, 'name="f-studio"'));
test('Form has WhatsApp field', str_contains($index, 'name="f-whatsapp"'));
test('Form has email field', str_contains($index, 'name="f-email"'));
test('Form has Instagram field', str_contains($index, 'name="f-instagram"'));
test('Form has revenue field', str_contains($index, 'name="f-revenue"'));
test('Form has ticket field', str_contains($index, 'name="f-ticket"'));

// Diagnostic endpoint reports missing env var clearly
$diagPhp = file_get_contents(__DIR__ . '/../api/leads/diagnostico.php');
test('diagnostico.php reads N8N_DIAGNOSTICO_WEBHOOK_URL', str_contains($diagPhp, "getenv('N8N_DIAGNOSTICO_WEBHOOK_URL')"),
    'Missing getenv N8N_DIAGNOSTICO_WEBHOOK_URL in diagnostico.php');
test('diagnostico.php fails clearly when env var missing', str_contains($diagPhp, 'N8N_DIAGNOSTICO_WEBHOOK_URL not set'),
    'diagnostico.php should report missing env var instead of silently succeeding');

// ── 7. Calculadora page isolated ───────────────────────
echo "\n=== Calculadora page ===\n";
test('Calculadora has inline style', str_contains($calc, '<style>'),
    'Missing inline style block');
test('Calculadora has form step', str_contains($calc, 'step') || str_contains($calc, 'pergunta'),
    'No form steps found');
test('Calculadora has lead form', str_contains($calc, 'lead-form') || str_contains($calc, 'qualifying') || str_contains($calc, 'form'),
    'Missing lead form on calculadora');

// ── 7b. Calculadora v2 — external file loads ────────────
echo "\n=== Calculadora v2 page ===\n";
test('Calculadora v2 loads', strlen($calc2) > 1000, 'Content length: ' . strlen($calc2));
test('Calculadora v2 loads style.css', str_contains($calc2, '<link rel="stylesheet" href="style.css">'));
test('Calculadora v2 loads css/calculadora.css', str_contains($calc2, '<link rel="stylesheet" href="css/calculadora.css">'));
test('Calculadora v2 loads js/main.js', str_contains($calc2, '<script src="js/main.js">'));
test('Calculadora v2 loads js/calculator.js', str_contains($calc2, '<script src="js/calculator.js">'));
test('Calculadora v2 loads js/calculadora-page.js', str_contains($calc2, '<script src="js/calculadora-page.js">'));
test('Calculadora v2 has NO inline style block',
    !str_contains($calc2, '<style>'),
    'Inline <style> block found — should use external CSS');
test('Calculadora v2 has calculator form', str_contains($calc2, 'id="calcForm"'));
test('Calculadora v2 has lead form', str_contains($calc2, 'id="leadForm"'));
test('Calculadora v2 uses marquee-set wrappers', str_contains($calc2, 'class="marquee-set"'));

// ── 7c. Calculadora warm-up video facade ────────────────
echo "\n=== Calculadora warm-up video ===\n";
$videoFacadeJs = fetch("{$BASE}/js/video-facade.js");
test('calculadora.php has warm-up video facade',
    str_contains($calc, 'data-video-id="oI9-nRi5gQ8"'),
    'Missing data-video-id facade on calculadora');
test('calculadora.php loads video-facade.js',
    str_contains($calc, 'js/video-facade.js'),
    'Missing video-facade.js include on calculadora');
test('calculadora.php dropped placeholder video image',
    !str_contains($calc, 'placehold.co/560x315'),
    'placehold.co video placeholder still present');
test('calculadora-v2.php has warm-up video facade',
    str_contains($calc2, 'data-video-id="oI9-nRi5gQ8"'),
    'Missing data-video-id facade on calculadora-v2');
test('calculadora-v2.php loads video-facade.js',
    str_contains($calc2, 'js/video-facade.js'),
    'Missing video-facade.js include on calculadora-v2');
test('video-facade.js uses privacy-enhanced embed',
    str_contains($videoFacadeJs, 'youtube-nocookie.com/embed'),
    'Facade does not use youtube-nocookie.com');
test('video-facade.js defers iframe until click',
    str_contains($videoFacadeJs, 'addEventListener') && !str_contains($videoFacadeJs, '<iframe'),
    'Facade does not defer iframe creation to a click');
test('video-facade.js drives any [data-video-id]',
    str_contains($videoFacadeJs, "querySelectorAll('[data-video-id]')"),
    'Facade selector is not generic');

// ── 7d. Calculadora testimonial video (Sergio Moraes) ──
echo "\n=== Calculadora testimonial video ===\n";
test('calculadora.php has testimonial video facade',
    str_contains($calc, 'data-video-id="xERLTwPdnPk"'),
    'Missing testimonial video facade on calculadora');
test('calculadora-v2.php has testimonial video facade',
    str_contains($calc2, 'data-video-id="xERLTwPdnPk"'),
    'Missing testimonial video facade on calculadora-v2');
test('testimonial video sits before the lead form',
    strpos($calc, 'data-video-id="xERLTwPdnPk"') !== false
        && strpos($calc, 'data-video-id="xERLTwPdnPk"') < strpos($calc, 'id="leadForm"'),
    'Testimonial video should appear before the lead form');
test('testimonial video credits the artist',
    str_contains($calc, '@sergiomoraestattoo'),
    'Missing @sergiomoraestattoo caption');
test('testimonial block hidden after lead submit',
    str_contains($calc, ".depoimento-video-block'"),
    'Testimonial block not hidden in calculadora.php submit handler');

// ── 7e. Carousel result images optimized to WebP ────────
echo "\n=== Carousel result images (WebP) ===\n";
foreach (['guitattoo', 'rsilva', 'dinho'] as $artist) {
    test("calculadora.php uses webp for {$artist} result photo",
        str_contains($calc, "/img/{$artist}_resultado.webp"),
        "Missing webp result photo for {$artist}");
}
test('calculadora.php carousel has no legacy raster refs',
    !str_contains($calc, '_resultado.png') && !str_contains($calc, '_resultado.jpeg'),
    'Legacy PNG/JPEG carousel reference still present');
test('calculadora.php carousel photos lazy-load',
    str_contains($calc, 'carousel-photo" src="/img/guitattoo_resultado.webp" alt="Gui Tattoo" loading="lazy"'),
    'Result photos are not lazy-loaded');
test('calculadora-v2.php uses webp result photos',
    str_contains($calc2, '/img/rsilva_resultado.webp'),
    'Missing webp result photo on calculadora-v2');
foreach (['guitattoo_resultado.webp', 'rsilva_resultado.webp', 'dinho_resultado.webp'] as $f) {
    $p = __DIR__ . '/../img/' . $f;
    $data = is_file($p) ? file_get_contents($p) : '';
    test("webp asset {$f} is valid WebP",
        substr($data, 0, 4) === 'RIFF' && substr($data, 8, 4) === 'WEBP',
        'Missing or invalid WebP file');
}
test('legacy result source images removed',
    !is_file(__DIR__ . '/../img/rsilva_resultado.png')
        && !is_file(__DIR__ . '/../img/dinho_resultado.png')
        && !is_file(__DIR__ . '/../img/guitattoo_resultado.jpeg'),
    'Legacy large source images still present');

// ── 7f. Logo + favicons optimized ───────────────────────
echo "\n=== Logo & favicons ===\n";
$logoWebp = __DIR__ . '/../img/vaif_logo.webp';
$lw = is_file($logoWebp) ? file_get_contents($logoWebp) : '';
test('vaif_logo.webp is valid WebP',
    substr($lw, 0, 4) === 'RIFF' && substr($lw, 8, 4) === 'WEBP',
    'Missing or invalid vaif_logo.webp');
test('Header nav logo uses webp',
    str_contains(file_get_contents(__DIR__ . '/../components/Header.php'), 'img/vaif_logo.webp'),
    'Nav logo not switched to webp');
test('Homepage footer logo uses webp',
    str_contains($index, 'img/vaif_logo.webp'),
    'Footer logo not switched to webp');
test('og:image keeps PNG for social crawlers',
    str_contains($index, 'https://vaif.com.br/img/vaif_logo.png'),
    'og:image should remain PNG');

$favicons = [
    'favicon-16x16.png' => 16,
    'favicon-32x32.png' => 32,
    'apple-touch-icon.png' => 180,
    'android-chrome-192x192.png' => 192,
    'android-chrome-512x512.png' => 512,
];
foreach ($favicons as $f => $size) {
    $p = __DIR__ . '/../img/favicon/' . $f;
    $d = is_file($p) ? file_get_contents($p) : '';
    $isPng = substr($d, 0, 8) === "\x89PNG\r\n\x1a\n";
    $w = $isPng ? unpack('N', substr($d, 16, 4))[1] : 0;
    $h = $isPng ? unpack('N', substr($d, 20, 4))[1] : 0;
    test("favicon {$f} is a {$size}x{$size} square PNG",
        $isPng && $w === $size && $h === $size,
        "got {$w}x{$h}");
}

$icoPath = __DIR__ . '/../img/favicon/favicon.ico';
$ico = is_file($icoPath) ? file_get_contents($icoPath) : '';
$icoCount = strlen($ico) >= 6 ? unpack('v', substr($ico, 4, 2))[1] : 0;
test('favicon.ico embeds 16/32/48', $icoCount === 3, "entries={$icoCount}");

$manifest = is_file(__DIR__ . '/../img/favicon/site.webmanifest')
    ? file_get_contents(__DIR__ . '/../img/favicon/site.webmanifest') : '';
test('webmanifest icon paths resolve under /img/favicon/',
    str_contains($manifest, '/img/favicon/android-chrome-192x192.png')
        && str_contains($manifest, '/img/favicon/android-chrome-512x512.png'),
    'webmanifest icon paths are wrong');

// ── 7g. Broken favicon paths on LP utility pages ────────
echo "\n=== Favicon paths (LP utility pages) ===\n";
foreach (['onboard/index.php', 'agenda/index.php'] as $page) {
    $src = file_get_contents(__DIR__ . '/../' . $page);
    test("{$page} links the existing favicon.ico",
        str_contains($src, 'href="/img/favicon/favicon.ico"'),
        'Favicon path is wrong');
    test("{$page} has no broken /img/favicon.ico link",
        !str_contains($src, 'href="/img/favicon.ico"'),
        'Broken favicon path still present');
    test("{$page} has the full favicon set",
        str_contains($src, 'apple-touch-icon')
            && str_contains($src, 'android-chrome-512x512.png')
            && str_contains($src, 'site.webmanifest'),
        'Incomplete favicon set');
}

// ── 7h. Qualification floor lowered to R$4k ─────────────
echo "\n=== Qualification gate (R$4k) ===\n";
$gateSources = [
    'calculadora.php' => $calc,
    'js/calculadora-page.js' => file_get_contents(__DIR__ . '/../js/calculadora-page.js'),
    'js/calculator.js' => file_get_contents(__DIR__ . '/../js/calculator.js'),
];
foreach ($gateSources as $name => $content) {
    test("{$name} gates at >= 4000",
        str_contains($content, 'faturamento >= 4000'),
        'Qualification floor not lowered');
    test("{$name} has no stale > 7000 gate",
        !str_contains($content, 'faturamento > 7000'),
        'Stale 7000 threshold still present');
}
test('Conviction copy references R$ 4.000',
    str_contains($calc, 'R$ 4.000 com realismo') && str_contains($calc2, 'R$ 4.000 com realismo'),
    'Conviction copy still says R$ 7.000');

// ── 8. JS files load ──────────────────────────────────
echo "\n=== JavaScript ===\n";
$mainJs = file_get_contents(__DIR__ . '/../js/main.js'); // Already loaded
test('main.js has input masks', str_contains($mainJs, 'setupInputMasks'));
test('main.js has scroll observer', str_contains($mainJs, 'setupScrollObserver'));
test('main.js has mobile nav toggle', str_contains($mainJs, 'setupMobileNav'));
test('Nav has hamburger button', str_contains($index, 'nav-hamburger'));
test('Nav has aria-label', str_contains($index, 'aria-label="Abrir menu"'));
test('Nav links id', str_contains($index, 'id="nav-links"'));
test('chat.js has ChatSimulator', str_contains($chatJs, 'ChatSimulator'));
test('chat.js has QualifyingForm', str_contains($chatJs, 'QualifyingForm'));

// ── 9. Artist landing pages (#24) ───────────────────────
echo "\n=== Artist landing pages (#24) ===\n";

$ARTIST_BASE = $BASE;

function fetch_artist(string $path): array
{
    global $ARTIST_BASE;
    $ch = curl_init("{$ARTIST_BASE}{$path}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $html = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$html, $code];
}

[$artistPage, $artistCode] = fetch_artist('/artists/joao-silva');
[, $missingCode] = fetch_artist('/artists/nonexistent');
[, $noSlugCode] = fetch_artist('/artists/');

test('Artist page returns 200', $artistCode === 200, "Got HTTP {$artistCode}");
test('Artist page contains display name', str_contains($artistPage, 'João Silva'),
    'Missing display name in output');

// Section IDs
$sectionIds = ['hero', 'portfolio', 'about', 'booking', 'testimonials', 'instagram', 'faq', 'location'];
foreach ($sectionIds as $sid) {
    test("Section #{$sid} present", str_contains($artistPage, "id=\"{$sid}\""),
        "Missing id=\"{$sid}\"");
}

// WhatsApp
test('WhatsApp link contains correct number', str_contains($artistPage, 'wa.me/5511999999999'),
    'WhatsApp number not found in page');
test('WhatsApp link has correct message text', str_contains($artistPage, 'vim%20pelo%20seu%20site%20no%20vaif.com.br'),
    'WhatsApp message text incorrect');

// Hero background video
test('Artist page renders hero video element', str_contains($artistPage, '<video'),
    'Missing hero <video> element');
test('Hero video autoplays muted and loops', str_contains($artistPage, 'autoplay') && str_contains($artistPage, 'muted') && str_contains($artistPage, 'loop'),
    'Hero video missing autoplay/muted/loop');
test('Hero video uses playsinline for iOS', str_contains($artistPage, 'playsinline'),
    'Missing playsinline attribute');
test('Hero video has poster fallback', str_contains($artistPage, 'poster="'),
    'Missing poster fallback');
test('Hero video uses configured source', str_contains($artistPage, 'joao-silva-hero.mp4'),
    'Configured video source not rendered');

// Matomo
test('Matomo trackEvent present', str_contains($artistPage, "_paq.push(['trackEvent', 'Artista', 'CTA_WhatsApp'"),
    'Matomo event tracking missing');
test('Matomo trackEvent includes slug', str_contains($artistPage, "'joao-silva'"),
    'Matomo event slug missing');

// JSON-LD
test('JSON-LD Person schema present', str_contains($artistPage, '"@type":"Person"'),
    'JSON-LD Person missing');
test('JSON-LD FAQPage schema present', str_contains($artistPage, '"@type":"FAQPage"'),
    'JSON-LD FAQPage missing');
test('JSON-LD TattooParlor schema present', str_contains($artistPage, '"@type":"TattooParlor"'),
    'JSON-LD TattooParlor missing');
test('JSON-LD BreadcrumbList schema present', str_contains($artistPage, '"@type":"BreadcrumbList"'),
    'JSON-LD BreadcrumbList missing');
test('Artist page has canonical URL', str_contains($artistPage, '<link rel="canonical" href="https://vaif.com.br/artists/joao-silva">'),
    'Missing canonical URL');

// Instagram post embeds
test('Artist page renders Instagram embed blockquote', str_contains($artistPage, 'data-instgrm-permalink'),
    'Missing data-instgrm-permalink');
test('Artist page embeds all configured posts',
    substr_count($artistPage, 'data-instgrm-permalink') >= 4,
    'Expected at least 4 post embeds');
test('Artist page loads Instagram embed.js', str_contains($artistPage, 'instagram.com/embed.js'),
    'Missing embed.js loader');
test('Artist page does not render placeholder posts when real posts configured',
    !str_contains($artistPage, 'placehold.co/300x300'),
    'Placeholder posts still rendered');
test('Artist page mixes photo and reel embeds',
    str_contains($artistPage, 'data-instgrm-permalink="https://www.instagram.com/reel/'),
    'Missing reel permalink in grid');
test('Artist page renders reels with spanning class', str_contains($artistPage, 'instagram-embed--reel'),
    'Missing instagram-embed--reel class');
test('Profile-scoped permalinks are normalized to canonical embed URLs',
    !str_contains($artistPage, 'data-instgrm-permalink="https://www.instagram.com/joaosilvatattoo/'),
    'Profile-scoped URL not normalized in permalink');
test('Normalized reel permalink rendered', str_contains($artistPage, 'data-instgrm-permalink="https://www.instagram.com/reel/C4fN8tRcDmQ/"'),
    'Canonical reel permalink missing after normalization');

// NotFound pages
test('Nonexistent artist returns 404', $missingCode === 404, "Got HTTP {$missingCode}");
test('No slug returns 404', $noSlugCode === 404, "Got HTTP {$noSlugCode}");

// ── 10. SEO: structured data, sitemap, robots.txt (#26) ──
echo "\n=== SEO (#26) ===\n";

$robots = fetch("{$BASE}/robots.txt");
test('robots.txt returns 200', strlen($robots) > 0);
test('robots.txt allows OAI-SearchBot', str_contains($robots, 'OAI-SearchBot'));
test('robots.txt allows GPTBot (AI discovery)', preg_match('/User-agent: GPTBot\s+Allow: \//', $robots) === 1);
test('robots.txt allows Google-Extended (AI discovery)', preg_match('/User-agent: Google-Extended\s+Allow: \//', $robots) === 1);
test('robots.txt references sitemap', str_contains($robots, 'Sitemap: https://vaif.com.br/sitemap.xml'));

// Test sitemap
function fetch_headers(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $response = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return [substr($response, 0, $headerSize), substr($response, $headerSize)];
}

[$sitemapHeaders, $sitemapBody] = fetch_headers("{$BASE}/sitemap.php");
test('sitemap.php returns XML content-type',
    str_contains($sitemapHeaders, 'xml') || str_starts_with($sitemapBody, '<?xml'),
    'Missing XML content type or declaration');
test('sitemap.xml contains urlset', str_contains($sitemapBody, '<urlset'));
test('sitemap.xml contains url entries', str_contains($sitemapBody, '<url>'));
test('sitemap.xml contains loc entries', str_contains($sitemapBody, '<loc>'));

// Homepage SEO
test('index.php has Organization JSON-LD', str_contains($index, '"@type":"Organization"'),
    'Missing Organization structured data');
test('index.php has canonical URL', str_contains($index, '<link rel="canonical" href="https://vaif.com.br/">'),
    'Missing canonical URL');
test('index.php has og:title', str_contains($index, '<meta property="og:title"'),
    'Missing og:title');
test('index.php has og:description', str_contains($index, '<meta property="og:description"'),
    'Missing og:description');
test('index.php has og:image', str_contains($index, '<meta property="og:image"'),
    'Missing og:image');
test('index.php has twitter:card', str_contains($index, '<meta name="twitter:card"'),
    'Missing twitter:card');

// Calculadora SEO
test('calculadora.php has canonical URL', str_contains($calc, '<link rel="canonical" href="https://vaif.com.br/calculadora/">'),
    'Missing canonical URL on calculadora');
test('calculadora.php has og:title', str_contains($calc, '<meta property="og:title"'),
    'Missing og:title on calculadora');
test('calculadora.php has twitter:card', str_contains($calc, '<meta name="twitter:card"'),
    'Missing twitter:card on calculadora');

// ── Summary ────────────────────────────────────────────
echo "\n" . str_repeat('═', 50) . "\n";
echo "  Results: {$passed} passed, {$failed} failed\n";
echo str_repeat('═', 50) . "\n\n";

exit($failed > 0 ? 1 : 0);
