<?php
declare(strict_types=1);

require_once __DIR__ . '/../../lib/blog/slugify.php';

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

echo "=== slugify tests ===\n";

test('Simple lowercase', slugify('Hello World') === 'hello-world');
test('Spaces to hyphens', slugify('Multiple   Spaces   Here') === 'multiple-spaces-here');
test('Special characters removed', slugify('Hello! @World #2026') === 'hello-world-2026');
test('Trailing hyphens trimmed', slugify('  padded  ') === 'padded');
test('Single word', slugify('word') === 'word');
test('Numbers preserved', slugify('Post 123 About Stuff') === 'post-123-about-stuff');
test('Multiple hyphens collapsed', slugify('a---b') === 'a-b');
test('Accented characters transliterated', slugify('Você está pronto') === 'voce-esta-pronto');
test('Portuguese accents', slugify('Não há limites para criação') === 'nao-ha-limites-para-criacao');
test('Empty string', slugify('') === '');
test('Only special chars', slugify('!@#$%') === '');

echo "\n" . str_repeat('═', 50) . "\n";
echo "  Results: {$passed} passed, {$failed} failed\n";
echo str_repeat('═', 50) . "\n\n";

exit($failed > 0 ? 1 : 0);
