<?php

namespace App\Services\Agent;

/**
 * Merges the fields the lead supplied in this turn into the turn facts.
 *
 * `build_turn_facts` already merges placement, size, style and type for the
 * price lookup, but the prompt and validator read `facts.project`. Without
 * this merge, the agent can re-ask the field the lead just answered (for
 * example, the meaning) because the project row is only updated after apply.
 */
class TurnFacts
{
    /**
     * @param  array<string, mixed>  $facts
     * @param  array<string, mixed>  $entities
     * @return array<string, mixed>
     */
    public static function mergeEntities(array $facts, array $entities): array
    {
        if (! is_array($facts['project'] ?? null)) {
            $facts['project'] = [];
        }

        foreach (['placement', 'body_zone', 'style', 'first_tattoo', 'meaning'] as $field) {
            $value = $entities[$field] ?? null;

            if ($value !== null && $value !== '') {
                $facts['project'][$field] = $value;
            }
        }

        $type = $entities['tattoo_type'] ?? $entities['tipo'] ?? null;

        if (is_string($type)) {
            $type = match (mb_strtolower(trim($type))) {
                'nova', 'new' => 'new',
                'cobertura', 'cover_up' => 'cover_up',
                'reforma', 'rework' => 'rework',
                default => null,
            };

            if ($type !== null) {
                $facts['project']['tattoo_type'] = $type;
            }
        }

        return $facts;
    }
}
