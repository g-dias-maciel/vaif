<?php

declare(strict_types=1);

/**
 * Artist loading shared by the public page, the profile API and llms.txt.
 *
 * Legacy `artists/config/<slug>.php` files take precedence over DB-backed
 * artists, matching the public page. A config-file artist is always public;
 * a DB artist is only public when its status is `published`.
 */

require_once __DIR__ . '/store.php';

/**
 * Load one artist by slug.
 *
 * @return array{artist: array, status: string, source: string, db_row: ?array}|null
 */
function artist_load_by_slug(string $slug): ?array
{
    if ($slug === '' || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9-]*$/', $slug)) {
        return null;
    }

    $configPath = artist_config_path($slug);
    if (file_exists($configPath)) {
        $artist = include $configPath;
        if (!is_array($artist)) {
            return null;
        }

        return [
            'artist' => $artist,
            'status' => 'published',
            'source' => 'config',
            'db_row' => null,
        ];
    }

    $pdo = artist_db();
    if ($pdo === null) {
        return null;
    }

    $row = artist_db_load_by_slug($pdo, $slug);
    if ($row === null) {
        return null;
    }

    return [
        'artist' => $row['data'],
        'status' => (string) ($row['status'] ?? 'draft'),
        'source' => 'db',
        'db_row' => $row,
    ];
}

/**
 * Every published artist, keyed by slug. Used by llms.txt and internal
 * discovery files. Config artists win over a DB row with the same slug.
 *
 * @return array<string, array{artist: array, status: string, source: string, db_row: ?array}>
 */
function artist_list_published(): array
{
    $artists = [];

    if (is_dir(ARTISTS_DIR . '/config')) {
        foreach (glob(ARTISTS_DIR . '/config/*.php') ?: [] as $file) {
            $artist = include $file;
            if (!is_array($artist) || empty($artist['slug'])) {
                continue;
            }
            $slug = (string) $artist['slug'];
            $artists[$slug] = [
                'artist' => $artist,
                'status' => 'published',
                'source' => 'config',
                'db_row' => null,
            ];
        }
    }

    $pdo = artist_db();
    if ($pdo !== null) {
        foreach (artist_db_list($pdo) as $row) {
            if ((string) ($row['status'] ?? '') !== 'published') {
                continue;
            }
            $slug = (string) ($row['slug'] ?? '');
            if ($slug === '' || isset($artists[$slug])) {
                continue;
            }
            $artists[$slug] = [
                'artist' => $row['data'],
                'status' => 'published',
                'source' => 'db',
                'db_row' => $row,
            ];
        }
    }

    ksort($artists);

    return $artists;
}
