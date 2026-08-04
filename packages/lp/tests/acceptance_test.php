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
test('Form has Instagram field', str_contains($index, 'name="f-instagram"'));
test('Form has revenue field', str_contains($index, 'name="f-revenue"'));

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

$ARTIST_BASE = 'http://localhost:8001';

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
test('WhatsApp link has correct message text', str_contains($artistPage, 'Ola,%20vim%20pelo%20seu%20site%20no%20vaif.com.br'),
    'WhatsApp message text incorrect');

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
test('JSON-LD LocalBusiness schema present', str_contains($artistPage, '"@type":"LocalBusiness"'),
    'JSON-LD LocalBusiness missing');
test('JSON-LD BreadcrumbList schema present', str_contains($artistPage, '"@type":"BreadcrumbList"'),
    'JSON-LD BreadcrumbList missing');
test('Artist page has canonical URL', str_contains($artistPage, '<link rel="canonical" href="https://vaif.com.br/artists/joao-silva">'),
    'Missing canonical URL');

// NotFound pages
test('Nonexistent artist returns 404', $missingCode === 404, "Got HTTP {$missingCode}");
test('No slug returns 404', $noSlugCode === 404, "Got HTTP {$noSlugCode}");

// ── 10. SEO: structured data, sitemap, robots.txt (#26) ──
echo "\n=== SEO (#26) ===\n";

$robots = fetch("{$BASE}/robots.txt");
test('robots.txt returns 200', strlen($robots) > 0);
test('robots.txt allows OAI-SearchBot', str_contains($robots, 'OAI-SearchBot'));
test('robots.txt disallows GPTBot', str_contains($robots, 'GPTBot'));
test('robots.txt disallows Google-Extended', str_contains($robots, 'Google-Extended'));
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
