<?php
declare(strict_types=1);

require_once __DIR__ . '/../../lib/blog/parse-frontmatter.php';
require_once __DIR__ . '/../../lib/blog/render-markdown.php';
require_once __DIR__ . '/../../lib/blog/slugify.php';
require_once __DIR__ . '/../../lib/blog/load-posts.php';

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

echo "=== load_posts tests ===\n";

// Create temp directory for blog posts
$tmpDir = sys_get_temp_dir() . '/blog-test-' . uniqid();
mkdir($tmpDir, 0777, true);

// Write valid sample posts
file_put_contents("{$tmpDir}/post-one.md", "---\ntitle: My First Post\ndate: 2026-01-15\nauthor: Jane\ntags: tech, php\ncategory: development\ndraft: false\n---\nThis is the body of my first post.");
file_put_contents("{$tmpDir}/post-two.md", "---\ntitle: Another Article\ndate: 2026-02-20\nslug: custom-slug\ndraft: false\n---\nAnother body here.");
file_put_contents("{$tmpDir}/draft-post.md", "---\ntitle: Secret Draft\ndate: 2026-03-01\ndraft: true\n---\nDraft body.");
file_put_contents("{$tmpDir}/no-title.md", "---\ndate: 2026-01-10\ndraft: false\n---\nMissing title here.");

// Test: load_posts returns posts sorted by date (drafts included)
$posts = load_posts($tmpDir);
test('load_posts returns an array', is_array($posts));
test('load_posts loaded 3 valid posts', count($posts) === 3);

// Check sorting (descending by date — draft with March 1 comes first)
$slugs = array_keys($posts);
test('Posts sorted by date desc (Mar draft first)', $slugs[0] === 'secret-draft');

// Check post structure
$firstPost = $posts['custom-slug'];
test('Post has title', $firstPost['title'] === 'Another Article');
test('Post has date', $firstPost['date'] === '2026-02-20');
test('Post has custom slug', $firstPost['slug'] === 'custom-slug');
test('Post has body', str_contains($firstPost['body'], 'Another body'));
test('Post has draft flag', $firstPost['draft'] === false);

// Check auto-slug generation
$secondPost = $posts['my-first-post'];
test('Auto-generated slug from title', $secondPost['slug'] === 'my-first-post');
test('Post has author field', ($secondPost['author'] ?? '') === 'Jane');
test('Post has tags', $secondPost['tags'] === ['tech', 'php']);
test('Post has category', ($secondPost['category'] ?? '') === 'development');

// Check description auto-generation
test('Post has description', !empty($secondPost['description']));
test('Description is excerpt of body', str_contains($secondPost['description'], 'This is the body'));

// Draft posts are included in load_posts result (filtering happens in front controller)
$foundDraft = false;
foreach ($posts as $p) {
    if (($p['slug'] ?? '') === 'secret-draft') $foundDraft = true;
}
test('Draft posts are loaded by load_posts', $foundDraft);

// Post missing title: skipped
$hasMissingTitle = false;
foreach ($posts as $p) {
    if (($p['slug'] ?? '') === 'no-title') $hasMissingTitle = true;
}
test('Post without title is skipped', !$hasMissingTitle);

// Empty directory
mkdir("{$tmpDir}/empty", 0777, true);
$emptyPosts = load_posts("{$tmpDir}/empty");
test('Empty directory returns empty array', $emptyPosts === []);

// Non-existent directory
$badPosts = load_posts("{$tmpDir}/nonexistent");
test('Non-existent directory returns empty array', $badPosts === []);

// Cleanup
array_map('unlink', glob("{$tmpDir}/*.md") ?: []);
rmdir("{$tmpDir}/empty");
rmdir($tmpDir);

echo "\n" . str_repeat('═', 50) . "\n";
echo "  Results: {$passed} passed, {$failed} failed\n";
echo str_repeat('═', 50) . "\n\n";

exit($failed > 0 ? 1 : 0);
