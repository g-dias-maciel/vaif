<?php

namespace App\Services\Agent\Eval;

use App\Models\Artist;
use App\Services\Agent\AgentMedia;
use App\Services\Agent\OpenRouterClient;

/**
 * E2E-only media boundary.
 *
 * The conversation model, PostgreSQL state, prompt, validator and handoff
 * rules are real. Only the vision result is supplied by the scenario, so the
 * test does not depend on camera-quality photos or WAHA downloads.
 */
class ScriptedAgentMedia extends AgentMedia
{
    /** @var array{text?: string, analysis?: array<string, mixed>}|null */
    public ?array $next = null;

    public function __construct()
    {
        parent::__construct(new OpenRouterClient());
    }

    public function prepare(
        Artist $artist,
        string $projectId,
        string $text,
        string $tattooType,
        array $history = [],
    ): array {
        if ($this->next !== null) {
            $next = $this->next;
            $this->next = null;

            return [
                'text' => (string) ($next['text'] ?? $text),
                'analysis' => is_array($next['analysis'] ?? null) ? $next['analysis'] : null,
            ];
        }

        $text = preg_replace('/\[\[REF_(?:FOTO|AUDIO):[^\]]+\]\]/', '', $text) ?? $text;

        return ['text' => trim($text), 'analysis' => null];
    }
}
