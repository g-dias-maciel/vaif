<?php

declare(strict_types=1);

require_once __DIR__ . '/parse-frontmatter.php';
require_once __DIR__ . '/slugify.php';

function load_posts(string $dir): array
{
    if (!is_dir($dir)) {
        return [];
    }

    $files = glob("{$dir}/*.md") ?: [];
    $posts = [];

    foreach ($files as $file) {
        $raw = @file_get_contents($file);
        if ($raw === false) {
            continue;
        }

        [$fm, $body] = parse_frontmatter($raw);

        $title = $fm['title'] ?? '';
        $date = $fm['date'] ?? '';

        if ($title === '') {
            error_log("Blog post {$file} missing required field: title");
            continue;
        }
        if ($date === '') {
            error_log("Blog post {$file} missing required field: date");
            continue;
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            error_log("Blog post {$file} has invalid date format: {$date}");
            continue;
        }

        $draft = ($fm['draft'] ?? '') === 'true';

        $slug = $fm['slug'] ?? '';
        if ($slug === '') {
            $slug = slugify($title);
        }

        $description = $fm['description'] ?? '';
        if ($description === '') {
            $stripped = preg_replace('/[#*>`!\[\]()\n]+/', ' ', $body);
            $stripped = preg_replace('/\s+/', ' ', $stripped);
            $description = trim(mb_substr($stripped, 0, 160));
        }

        $tags = [];
        if (isset($fm['tags']) && $fm['tags'] !== '') {
            $tags = array_map('trim', explode(',', $fm['tags']));
        }

        $posts[] = [
            'title' => $title,
            'date' => $date,
            'slug' => $slug,
            'description' => $description,
            'author' => $fm['author'] ?? '',
            'featured_image' => $fm['featured_image'] ?? '',
            'tags' => $tags,
            'category' => $fm['category'] ?? '',
            'draft' => $draft,
            'body' => $body,
            'file' => $file,
        ];
    }

    usort($posts, function (array $a, array $b): int {
        return strcmp($b['date'], $a['date']);
    });

    $indexed = [];
    foreach ($posts as $post) {
        $indexed[$post['slug']] = $post;
    }

    return $indexed;
}
