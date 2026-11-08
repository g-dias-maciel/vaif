<?php

namespace App\Services\Agent\Eval;

use App\Services\Agent\AiClient;
use App\Services\Agent\AgentException;

/**
 * Deterministic AiClient used by `agent:simulate` and the PHPUnit scenarios.
 *
 * The prompt itself decides which scripted answer to return, so a scenario
 * only declares one entry per model role.
 */
class ScriptedAiClient implements AiClient
{
    /** @var array<int, string> */
    public array $calls = [];

    public function __construct(private readonly array $script)
    {
    }

    public function chat(array $messages, string $model): string
    {
        $system = (string) ($messages[0]['content'] ?? '');
        $role = $this->role($system);
        $this->calls[] = $role;

        if (! array_key_exists($role, $this->script)) {
            throw new AgentException("Scenario has no scripted response for '{$role}'.");
        }

        $value = $this->script[$role];

        return is_string($value)
            ? $value
            : (json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
    }

    private function role(string $system): string
    {
        return match (true) {
            str_contains($system, 'Preflight — interpretar') => 'preflight',
            str_contains($system, 'Claims — o que a resposta') => 'claims',
            str_contains($system, 'Reparar a resposta') => 'repair',
            str_contains($system, 'Calibração — comparar') => 'judge',
            default => 'agent',
        };
    }
}
