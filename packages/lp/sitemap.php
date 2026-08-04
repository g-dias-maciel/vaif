<?php

declare(strict_types=1);

header('Content-Type: application/xml; charset=utf-8');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";

$baseUrl = 'https://vaif.com.br';

function formatDate(string $date): string
{
    return date('Y-m-d', strtotime($date));
}

function fileMtimeDate(string $path): string
{
    $mtime = @filemtime($path);
    return $mtime ? date('Y-m-d', $mtime) : date('Y-m-d');
}

require_once __DIR__ . '/lib/blog/parse-frontmatter.php';

?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <url>
        <loc><?php echo $baseUrl; ?>/</loc>
        <changefreq>weekly</changefreq>
        <priority>1.0</priority>
    </url>
    <url>
        <loc><?php echo $baseUrl; ?>/calculadora/</loc>
        <changefreq>weekly</changefreq>
        <priority>0.8</priority>
    </url>
<?php

$blogDir = __DIR__ . '/content/blog';
if (is_dir($blogDir)) {
    $files = glob("{$blogDir}/*.md") ?: [];
    foreach ($files as $file) {
        $raw = @file_get_contents($file);
        if ($raw === false) {
            continue;
        }
        [$fm] = parse_frontmatter($raw);

        $draft = ($fm['draft'] ?? '') === 'true';
        if ($draft) {
            continue;
        }

        $slug = $fm['slug'] ?? '';
        if ($slug === '') {
            continue;
        }

        $lastmod = '';
        if (!empty($fm['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fm['date'])) {
            $lastmod = formatDate($fm['date']);
        } else {
            $lastmod = fileMtimeDate($file);
        }
?>
    <url>
        <loc><?php echo $baseUrl; ?>/blog/<?php echo htmlspecialchars($slug, ENT_XML1, 'UTF-8'); ?></loc>
        <lastmod><?php echo $lastmod; ?></lastmod>
        <changefreq>weekly</changefreq>
        <priority>0.7</priority>
    </url>
<?php
    }
}

$artistsDir = __DIR__ . '/artists/config';
if (is_dir($artistsDir)) {
    $artistFiles = glob("{$artistsDir}/*.php") ?: [];
    foreach ($artistFiles as $file) {
        $config = include $file;
        if (!is_array($config) || empty($config['slug'])) {
            continue;
        }
        $slug = $config['slug'];
        $lastmod = fileMtimeDate($file);
?>
    <url>
        <loc><?php echo $baseUrl; ?>/artists/<?php echo htmlspecialchars($slug, ENT_XML1, 'UTF-8'); ?></loc>
        <lastmod><?php echo $lastmod; ?></lastmod>
        <changefreq>monthly</changefreq>
        <priority>0.6</priority>
    </url>
<?php
    }
}
?>
</urlset>
