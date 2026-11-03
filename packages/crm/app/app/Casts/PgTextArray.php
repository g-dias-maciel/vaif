<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Laravel's built-in `array` cast writes JSON (`["a","b"]`), but the CRM's
 * `specialties`, `excluded_styles` and `blocked_numbers` columns are Postgres
 * `text[]`, which expects `{a,b}`. This cast converts both ways.
 *
 * On SQLite (tests) the same literal is stored in the JSON column and parsed
 * back the same way, so the model behaves identically in both environments.
 */
class PgTextArray implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes): array
    {
        if ($value === null) {
            return [];
        }

        if (is_array($value)) {
            return array_values($value);
        }

        $value = trim((string) $value);

        if ($value === '' || $value === '{}') {
            return [];
        }

        // Strip the outer braces and parse the Postgres array elements,
        // including quoted values with commas/escapes.
        $inner = substr($value, 1, -1);
        if ($inner === '') {
            return [];
        }

        $items = str_getcsv($inner, ',', '"', '\\');

        return array_values(array_filter(
            array_map(fn ($item) => trim((string) $item), $items),
            fn (string $item) => $item !== '',
        ));
    }

    public function set($model, string $key, $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $items = array_values(array_filter(
            array_map(fn ($item) => (string) $item, (array) $value),
            fn (string $item) => $item !== '',
        ));

        if ($items === []) {
            return '{}';
        }

        $escaped = array_map(
            fn (string $item) => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $item).'"',
            $items,
        );

        return '{'.implode(',', $escaped).'}';
    }
}
