<?php

declare(strict_types=1);

/**
 * Frame options for the before/after comparison block.
 *
 * The block shows two photos side by side behind a slider. When the two photos
 * have different proportions (common with phone shots), cropping one to fill a
 * fixed frame hides part of the tattoo — so the default is `contain` (show the
 * whole image). Artists can opt into `cover` per section/item.
 */

const ARTIST_BA_DEFAULT_ASPECT = '4 / 5';
const ARTIST_BA_DEFAULT_FIT = 'contain';

/**
 * Validate/normalize an `aspect` value (e.g. "3/4" → "3 / 4"), falling back to
 * the default for anything that is not a positive `w/h` ratio.
 */
function normalize_ba_aspect(mixed $value): string
{
    if (!is_string($value)) {
        return ARTIST_BA_DEFAULT_ASPECT;
    }
    if (!preg_match('#^\s*(\d+(?:\.\d+)?)\s*/\s*(\d+(?:\.\d+)?)\s*$#', $value, $m)) {
        return ARTIST_BA_DEFAULT_ASPECT;
    }
    if ((float) $m[1] <= 0 || (float) $m[2] <= 0) {
        return ARTIST_BA_DEFAULT_ASPECT;
    }
    return $m[1] . ' / ' . $m[2];
}

/**
 * Validate a `fit` value; only `cover` and `contain` are allowed.
 */
function normalize_ba_fit(mixed $value): string
{
    if (is_string($value)) {
        $value = strtolower(trim($value));
        if (in_array($value, ['cover', 'contain'], true)) {
            return $value;
        }
    }
    return ARTIST_BA_DEFAULT_FIT;
}
