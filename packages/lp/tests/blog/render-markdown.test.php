<?php
declare(strict_types=1);

require_once __DIR__ . '/../../lib/blog/render-markdown.php';

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

echo "=== render_markdown tests ===\n";

// Headings
test('H1', render_markdown("# Heading 1") === "<h1>Heading 1</h1>");
test('H2', render_markdown("## Heading 2") === "<h2>Heading 2</h2>");
test('H3', render_markdown("### Heading 3") === "<h3>Heading 3</h3>");

// Bold
test('Bold', render_markdown("This is **bold** text") === "<p>This is <strong>bold</strong> text</p>");

// Italic
test('Italic', render_markdown("This is *italic* text") === "<p>This is <em>italic</em> text</p>");

// Bold + Italic together
test('Bold and italic', render_markdown("**bold** and *italic*") === "<p><strong>bold</strong> and <em>italic</em></p>");

// Links
test('Links', render_markdown("[click here](https://example.com)") === '<p><a href="https://example.com">click here</a></p>');

// Images
test('Images', render_markdown("![alt text](/img/photo.jpg)") === '<p><img src="/img/photo.jpg" alt="alt text"></p>');

// Unordered lists
$list = "- item 1\n- item 2\n- item 3";
test('Unordered list', render_markdown($list) === "<ul><li>item 1</li><li>item 2</li><li>item 3</li></ul>");

// Ordered lists
$olist = "1. first\n2. second\n3. third";
test('Ordered list', render_markdown($olist) === "<ol><li>first</li><li>second</li><li>third</li></ol>");

// Blockquotes
test('Blockquote', render_markdown("> quoted text") === "<blockquote><p>quoted text</p></blockquote>");

// Code fences
$code = "```\nconst x = 1;\necho x;\n```";
$result = render_markdown($code);
test('Code fence wraps in pre/code', str_contains($result, '<pre><code>') && str_contains($result, '</code></pre>'));

// Horizontal rules
test('HR ---', render_markdown("---") === "<hr>");
test('HR ***', render_markdown("***") === "<hr>");
test('HR ___', render_markdown("___") === "<hr>");

// Inline code
test('Inline code', render_markdown("Use `echo` to print") === "<p>Use <code>echo</code> to print</p>");

// Paragraphs
test('Simple paragraph', render_markdown("A simple paragraph.") === "<p>A simple paragraph.</p>");

// Multiple paragraphs
test('Multiple paragraphs', render_markdown("Para one.\n\nPara two.") === "<p>Para one.</p><p>Para two.</p>");

// Complex document
$doc = "# Title\n\n**Bold** and *italic* with [a link](https://ex.com) and `code`.\n\n![img](/p.jpg)\n\n> Quote block\n\n- list item 1\n- list item 2\n";
$html = render_markdown($doc);
test('Complex doc has H1', str_contains($html, '<h1>Title</h1>'));
test('Complex doc has strong', str_contains($html, '<strong>Bold</strong>'));
test('Complex doc has em', str_contains($html, '<em>italic</em>'));
test('Complex doc has link', str_contains($html, '<a href="https://ex.com">a link</a>'));
test('Complex doc has code', str_contains($html, '<code>code</code>'));
test('Complex doc has image', str_contains($html, '<img src="/p.jpg" alt="img">'));
test('Complex doc has blockquote', str_contains($html, '<blockquote>'));
test('Complex doc has ul', str_contains($html, '<ul>'));
test('Complex doc has li', str_contains($html, '<li>list item 1</li>'));

// Markdown inside code fence preserved
$codeInside = "```\n# not a heading\n**not bold**\n```";
$result = render_markdown($codeInside);
test('Code fence preserves markdown inside', !str_contains($result, '<h1>') && !str_contains($result, '<strong>'));

echo "\n" . str_repeat('═', 50) . "\n";
echo "  Results: {$passed} passed, {$failed} failed\n";
echo str_repeat('═', 50) . "\n\n";

exit($failed > 0 ? 1 : 0);
