<?php
declare(strict_types=1);

require_once __DIR__ . '/../../lib/blog/parse-frontmatter.php';

$passed = 0;
$failed = 0;

function test(string $label, bool $condition, string $detail = ''): void {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "✅ PASS: {$label}\n";
    } else {
        $failed++;
        $msg = $detail ? " — {$detail}" : '';
        echo "❌ FAIL: {$label}{$msg}\n";
    }
}

echo "=== parse_frontmatter tests ===\n";

// Basic frontmatter
[$fm, $body] = parse_frontmatter("---\ntitle: Hello\ndate: 2026-01-15\n---\nBody text here.");
test('Parses title from frontmatter', ($fm['title'] ?? '') === 'Hello');
test('Parses date from frontmatter', ($fm['date'] ?? '') === '2026-01-15');
test('Extracts body text', $body === 'Body text here.');

// No frontmatter — whole file is body
[$fm, $body] = parse_frontmatter("Just a plain markdown file.\nNo frontmatter here.");
test('No frontmatter returns empty array', $fm === []);
test('No frontmatter: whole file is body', $body === "Just a plain markdown file.\nNo frontmatter here.");

// Leading whitespace before frontmatter
[$fm, $body] = parse_frontmatter("\n\n---\ntitle: Spaced\n---\nBody");
test('Handles leading whitespace before frontmatter', ($fm['title'] ?? '') === 'Spaced');

// Draft: true / false
[$fm, $body] = parse_frontmatter("---\ntitle: Drafty\ndraft: true\n---\nDraft body.");
test('Parses draft: true as string', ($fm['draft'] ?? '') === 'true');

[$fm, $body] = parse_frontmatter("---\ntitle: Published\ndraft: false\n---\nPublished body.");
test('Parses draft: false as string', ($fm['draft'] ?? '') === 'false');

// Tags with commas
[$fm, $body] = parse_frontmatter("---\ntitle: Tagged\ntags: php, blog, markdown\n---\nBody");
test('Parses tags field', ($fm['tags'] ?? '') === 'php, blog, markdown');

// URL values with colons
[$fm, $body] = parse_frontmatter("---\ntitle: With Image\nfeatured_image: https://example.com/img/hero.jpg\n---\nBody");
test('Handles value with colon (URL)', ($fm['featured_image'] ?? '') === 'https://example.com/img/hero.jpg');

// Unterminated frontmatter (--- at EOF)
[$fm, $body] = parse_frontmatter("---\ntitle: Unterminated\n---");
test('Unterminated frontmatter parses title', ($fm['title'] ?? '') === 'Unterminated');
test('Unterminated frontmatter has empty body', $body === '');

// Empty body
[$fm, $body] = parse_frontmatter("---\ntitle: Empty\n---\n\n");
test('Empty body after frontmatter', $body === '');

// Empty file
[$fm, $body] = parse_frontmatter("");
test('Empty file: empty frontmatter', $fm === []);
test('Empty file: empty body', $body === '');

// Empty lines in frontmatter
[$fm, $body] = parse_frontmatter("---\ntitle: Skipping\n\ncategory: design\n---\nBody");
test('Skips empty lines in frontmatter', ($fm['title'] ?? '') === 'Skipping' && ($fm['category'] ?? '') === 'design');

// Multiple values with same key (last wins)
[$fm, $body] = parse_frontmatter("---\ntitle: First\ntitle: Second\n---\nBody");
test('Duplicate keys: last value wins', ($fm['title'] ?? '') === 'Second');

// Only opening delimiter, no closing
[$fm, $body] = parse_frontmatter("---\ntitle: Unclosed\n\nBody here");
test('Only opening delimiter: treated as no frontmatter', $fm === []);

echo "\n" . str_repeat('═', 50) . "\n";
echo "  Results: {$passed} passed, {$failed} failed\n";
echo str_repeat('═', 50) . "\n\n";

exit($failed > 0 ? 1 : 0);
