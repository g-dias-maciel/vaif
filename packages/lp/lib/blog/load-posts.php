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

        $faq = [];
        if (!empty($fm['faq_json'])) {
            $decoded = json_decode($fm['faq_json'], true);
            if (is_array($decoded)) {
                foreach ($decoded as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $q = trim((string) ($item['question'] ?? $item['q'] ?? ''));
                    $a = trim((string) ($item['answer'] ?? $item['a'] ?? ''));
                    if ($q !== '' && $a !== '') {
                        $faq[] = ['question' => $q, 'answer' => $a];
                    }
                }
            }
        }

        $sources = [];
        if (!empty($fm['sources_json'])) {
            $decoded = json_decode($fm['sources_json'], true);
            if (is_array($decoded)) {
                foreach ($decoded as $source) {
                    if (is_string($source) && trim($source) !== '') {
                        $sources[] = trim($source);
                    }
                }
            }
        }

        $updated = $fm['updated'] ?? $date;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $updated)) {
            $updated = $date;
        }

        $wordCount = count(preg_split('/\s+/u', trim(strip_tags($body))) ?: []);

        $posts[] = [
            'title' => $title,
            'date' => $date,
            'updated' => $updated,
            'slug' => $slug,
            'description' => $description,
            'author' => $fm['author'] ?? '',
            'author_url' => $fm['author_url'] ?? '',
            'artist_slug' => $fm['artist_slug'] ?? '',
            'featured_image' => $fm['featured_image'] ?? '',
            'featured_image_alt' => $fm['featured_image_alt'] ?? $title,
            'tags' => $tags,
            'category' => $fm['category'] ?? '',
            'faq' => $faq,
            'sources' => $sources,
            'word_count' => $wordCount,
            'reading_time' => max(1, (int) ceil($wordCount / 200)),
            'draft' => $draft,
            'body' => $body,
            'file' => $file,
        ];
    }

    usort($posts, function (array $a, array $b): int {
        $byDate = strcmp($b['date'], $a['date']);
        if ($byDate !== 0) {
            return $byDate;
        }

        return strcmp($b['slug'], $a['slug']);
    });

    $indexed = [];
    foreach ($posts as $post) {
        if (isset($indexed[$post['slug']])) {
            error_log("Blog post {$post['file']} duplicates slug {$post['slug']}; keeping the first file");
            continue;
        }
        $indexed[$post['slug']] = $post;
    }

    return $indexed;
}
