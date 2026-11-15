<?php

namespace App\Services\Agent;

/**
 * Decides whether the cover-up photo (the tattoo the lead already has) is
 * already in the conversation.
 *
 * The vision analysis classifies each image as `tatuagem_atual`,
 * `referencia` or `outra`, using the chat as context. Only a current-tattoo
 * image counts as the cover-up photo: a reference image of someone else's
 * tattoo must not stop the agent from asking for the photo of the lead's own
 * tattoo.
 */
class CoverPhoto
{
    /**
     * @param  array<string, mixed>  $projectAnalysis  Last stored project analysis (historical photo).
     * @param  array<string, mixed>|null  $currentAnalysis  Analysis of the photo in this turn.
     * @param  array<string, mixed>  $flags  Preflight flags.
     */
    public static function received(
        array $projectAnalysis,
        ?array $currentAnalysis,
        array $flags,
        ?string $tattooType,
    ): bool {
        $knownRole = false;

        foreach ([$currentAnalysis, $projectAnalysis] as $analysis) {
            if (! is_array($analysis)) {
                continue;
            }

            $role = $analysis['papel'] ?? null;

            if ($role === 'tatuagem_atual') {
                return true;
            }

            if (in_array($role, ['referencia', 'outra'], true)) {
                $knownRole = true;
            }
        }

        // An explicit role is authoritative: a reference image is not the
        // lead's current tattoo. Only historical analyses (before roles) and
        // simulated drafts fall through to the photo flag.
        if ($knownRole) {
            return false;
        }

        return ! empty($flags['lead_photos'])
            && (! empty($flags['mentions_coverup']) || in_array($tattooType, ['cover_up', 'rework'], true));
    }
}
