<?php

declare(strict_types=1);

/**
 * Media URL resolution for artist landing pages.
 *
 * Photos may be absolute URLs (CDN/Unsplash) or bare filenames that live under
 * artists/<slug>/media/. Hero and bio photos can be configured separately from
 * the profile photo, and fall back to it while a local file is not yet in place.
 */

/**
 * Resolve a single image reference to a browser URL.
 *
 * - absolute / protocol-relative / root-absolute URLs pass through untouched
 * - a bare filename is served from /artists/<slug>/media/
 */
function artist_media_url(string $img, string $slug): string
{
    if ($img === '') {
        return '';
    }
    if (str_starts_with($img, 'http://') || str_starts_with($img, 'https://') || str_starts_with($img, '//')) {
        return $img;
    }
    if (str_starts_with($img, '/')) {
        return $img;
    }
    return "/artists/{$slug}/media/{$img}";
}

/**
 * Whether an image reference is a bare filename (i.e. local media).
 */
function artist_image_is_local(string $img): bool
{
    return $img !== ''
        && !str_starts_with($img, 'http://')
        && !str_starts_with($img, 'https://')
        && !str_starts_with($img, '//')
        && !str_starts_with($img, '/');
}

/**
 * Resolve the preferred photo for a section.
 *
 * Tries the section key (e.g. `hero_photo`) first, then `profile_photo`.
 * When `$mediaDir` is provided, a local candidate is only used if the file
 * actually exists there, so a not-yet-uploaded photo falls back gracefully.
 */
function artist_preferred_photo(array $artist, string $key, string $slug, string $mediaDir = ''): string
{
    $candidates = [];
    foreach ([$key, 'profile_photo'] as $candidateKey) {
        $value = $artist[$candidateKey] ?? '';
        if (is_string($value) && $value !== '' && !in_array($value, $candidates, true)) {
            $candidates[] = $value;
        }
    }
    if ($candidates === []) {
        return '';
    }

    foreach ($candidates as $img) {
        if ($mediaDir !== '' && artist_image_is_local($img) && !is_file(rtrim($mediaDir, '/') . '/' . $img)) {
            continue; // local file not uploaded yet — try the next candidate
        }
        return artist_media_url($img, $slug);
    }

    // Nothing resolved on disk: return the first candidate so the intent is
    // preserved (and a missing file is visible rather than silently dropped).
    return artist_media_url($candidates[0], $slug);
}
