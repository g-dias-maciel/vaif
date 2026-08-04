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

// ── Summary ────────────────────────────────────────────
echo "\n" . str_repeat('═', 50) . "\n";
echo "  Results: {$passed} passed, {$failed} failed\n";
echo str_repeat('═', 50) . "\n\n";

exit($failed > 0 ? 1 : 0);
