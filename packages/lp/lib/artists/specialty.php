<?php

declare(strict_types=1);

/**
 * Specialty gating for artist landing-page blocks.
 *
 * Some sections only make sense for artists who own a particular specialty —
 * the cover-up "antes e depois" block, for example. To keep those sections off
 * every other artist, an artist must explicitly declare a `specialty` slug in
 * their config and provide the block's items.
 */

/**
 * The artist's declared specialty slug, or '' when none is set.
 */
function artist_specialty(array $artist): string
{
    $specialty = $artist['specialty'] ?? '';
    if (!is_string($specialty)) {
        return '';
    }
    return trim($specialty);
}

/**
 * Whether the artist has explicitly declared a specialty.
 */
function artist_has_specialty(array $artist): bool
{
    return artist_specialty($artist) !== '';
}

/**
 * Whether the cover-up before/after block should render for this artist.
 *
 * Requires both an explicitly declared specialty and at least one complete
 * item (with both `before` and `after` images).
 */
function artist_show_before_after(array $artist): bool
{
    if (!artist_has_specialty($artist)) {
        return false;
    }

    foreach ($artist['before_after']['items'] ?? [] as $item) {
        if (!empty($item['before']) && !empty($item['after'])) {
            return true;
        }
    }

    return false;
}
