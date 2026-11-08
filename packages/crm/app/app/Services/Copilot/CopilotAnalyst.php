<?php

namespace App\Services\Copilot;

use App\Services\Agent\AiClient;
use App\Services\Agent\Json;
use Illuminate\Support\Facades\Log;

/**
 * Turns deterministic facts into a short narrative + findings.
 *
 * The model never receives tools or execute permissions; its output is text
 * and suggested actions only.
 */
class CopilotAnalyst
{
    public function __construct(private readonly AiClient $ai)
    {
    }

    /**
     * @return array{narrative: string, findings: array<int, array<string, mixed>>, model: ?string, error: bool}
     */
    public function analyze(string $kind, array $facts): array
    {
        $model = (string) config('copilot.model');

        try {
            $system = (string) file_get_contents(resource_path('prompts/copilot-analysis.md'));

            $content = $this->ai->chat([
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => 'TIPO: '.$kind."\n\nFATOS:\n".Json::string($facts)],
            ], $model);
        } catch (\Throwable $e) {
            Log::warning('Copilot analysis failed', ['kind' => $kind, 'error' => $e->getMessage()]);

            return $this->fallback($model);
        }

        $parsed = Json::parse($content);

        if ($parsed === []) {
            return $this->fallback($model);
        }

        $findings = [];
        $limit = (int) config('copilot.max_findings', 6);

        foreach ((array) ($parsed['findings'] ?? []) as $finding) {
            if (! is_array($finding) || count($findings) >= $limit) {
                continue;
            }

            $title = trim((string) ($finding['title'] ?? ''));
            if ($title === '') {
                continue;
            }

            $severity = strtolower((string) ($finding['severity'] ?? 'info'));
            if (! in_array($severity, ['info', 'warning', 'critical'], true)) {
                $severity = 'info';
            }

            $findings[] = [
                'severity' => $severity,
                'title' => mb_substr($title, 0, 120),
                'body' => mb_substr(trim((string) ($finding['body'] ?? '')), 0, 600),
                'evidence' => $this->strings($finding['evidence'] ?? [], 6),
                'suggested_action' => mb_substr(trim((string) ($finding['suggested_action'] ?? '')), 0, 500),
            ];
        }

        $narrative = trim((string) ($parsed['summary'] ?? ''));

        if ($narrative === '') {
            return $this->fallback($model);
        }

        return [
            'narrative' => mb_substr($narrative, 0, 1200),
            'findings' => $findings,
            'model' => $model,
            'error' => false,
        ];
    }

    /**
     * @return array{narrative: string, findings: array<int, array<string, mixed>>, model: ?string, error: bool}
     */
    private function fallback(string $model): array
    {
        return [
            'narrative' => 'Relatório determinístico gerado. A análise automática não pôde ser produzida agora; os números e sinais abaixo continuam válidos.',
            'findings' => [],
            'model' => $model,
            'error' => true,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function strings(mixed $value, int $limit): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_slice(array_values(array_filter(array_map(
            fn ($item) => mb_substr(trim((string) $item), 0, 200),
            $value,
        ))), 0, $limit);
    }
}
