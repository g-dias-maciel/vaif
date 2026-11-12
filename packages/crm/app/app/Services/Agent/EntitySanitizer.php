<?php

namespace App\Services\Agent;

/**
 * Keeps only explicit, canonical discovery values for a Project.
 *
 * Shared by live observation and the onboarding backfill so both paths write
 * the same shape. Assumptions are never passed through here.
 */
final class EntitySanitizer
{
    /**
     * @param  array<string, mixed>  $entities
     * @return array<string, mixed>
     */
    public static function sanitize(array $entities): array
    {
        $out = [];

        $placements = array_keys((array) config('crm.placement_labels', []));
        $placement = $entities['placement'] ?? null;
        if (is_string($placement) && in_array($placement, $placements, true)) {
            $out['placement'] = $placement;
        }

        $zones = array_keys((array) config('crm.body_zone_labels', []));
        $zone = $entities['body_zone'] ?? null;
        if (is_string($zone) && in_array($zone, $zones, true)) {
            $out['body_zone'] = $zone;
        }

        $style = $entities['style'] ?? null;
        if (is_string($style) && trim($style) !== '') {
            $out['style'] = mb_substr(trim($style), 0, 60);
        }

        $type = $entities['tattoo_type'] ?? $entities['tipo'] ?? null;
        if (is_string($type)) {
            $type = mb_strtolower(trim($type));
            $type = match ($type) {
                'nova', 'new' => 'nova',
                'cobertura', 'cover_up' => 'cobertura',
                'reforma', 'rework' => 'reforma',
                default => null,
            };

            if ($type !== null) {
                $out['tattoo_type'] = $type;
            }
        }

        $first = $entities['first_tattoo'] ?? null;
        if (is_bool($first)) {
            $out['first_tattoo'] = $first;
        } elseif ($first === 'true' || $first === 'false') {
            $out['first_tattoo'] = $first === 'true';
        }

        $meaning = $entities['meaning'] ?? null;
        if (is_string($meaning) && trim($meaning) !== '') {
            $out['meaning'] = mb_substr(trim($meaning), 0, 500);
        }

        $name = $entities['name'] ?? null;
        if (is_string($name) && trim($name) !== '') {
            $out['name'] = mb_substr(trim($name), 0, 80);
        }

        return $out;
    }
}
