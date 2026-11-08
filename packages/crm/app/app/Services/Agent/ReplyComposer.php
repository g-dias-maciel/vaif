<?php

namespace App\Services\Agent;

use App\Models\Artist;

/**
 * The generation + audit half of a turn: prompt, model reply, claim extraction,
 * validation and one repair attempt.
 *
 * Both the auto-reply path and the copilot draft path use this exact code, so a
 * draft is what auto mode would have sent.
 */
class ReplyComposer
{
    public function __construct(
        private readonly AiClient $ai,
        private readonly PromptBuilder $prompts,
        private readonly ClaimExtractor $claims,
        private readonly ReplyValidator $validator,
    ) {
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $history
     * @return array<string, mixed>
     */
    public function compose(
        Artist $artist,
        array $turn,
        array $facts,
        string $leadText,
        array $history,
        array $preflight,
    ): array {
        $messages = $this->prompts->messages($artist, $turn, $facts, $leadText, $history);
        $reply = $this->ai->chat($messages, (string) config('agent.models.agent'));
        $claims = $this->claims->extract($reply, $facts);
        $validation = $this->validator->validate($facts, $claims, $reply, $preflight);
        $originalViolations = $validation['violations'];
        $repaired = false;

        if (! $validation['ok'] && $validation['repairable']) {
            $repair = $this->repair($facts, $validation, $reply);

            if ($repair['reply'] !== '') {
                $second = $this->validator->validate($facts, $repair['claims'], $repair['reply'], $preflight);

                if ($second['final_reply'] !== '') {
                    $second['repaired'] = true;
                    $second['original_violations'] = $originalViolations;
                    $validation = $second;
                    $repaired = true;
                }
            }
        }

        return [
            'reply' => (string) $validation['final_reply'],
            'raw_reply' => $reply,
            'claims' => $claims,
            'validation' => $validation,
            'original_violations' => $originalViolations,
            'repaired' => $repaired,
        ];
    }

    /**
     * @return array{reply: string, claims: array<int, array<string, mixed>>}
     */
    private function repair(array $facts, array $validation, string $original): array
    {
        $system = (string) file_get_contents(resource_path('prompts/repair-reply.md'));

        $content = $this->ai->chat([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => 'FATOS:\n'.Json::string($facts)
                .'\n\nVIOLAÇÕES:\n'.Json::string($validation['violations'])
                .'\n\nRESPOSTA ATUAL:\n'.$original],
        ], (string) config('agent.models.repair'));

        $parsed = Json::parse($content);

        return [
            'reply' => trim((string) ($parsed['reply'] ?? '')),
            'claims' => is_array($parsed['claims'] ?? null) ? array_values(array_filter($parsed['claims'], 'is_array')) : [],
        ];
    }
}
