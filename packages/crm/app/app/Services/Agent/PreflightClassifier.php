<?php

namespace App\Services\Agent;

class PreflightClassifier
{
    public function __construct(
        private readonly AiClient $ai,
        private readonly string $model,
    ) {
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $history
     * @return array{intent: string, entities: array, flags: array, confidence: float}
     */
    public function classify(string $context, string $leadText, array $history = []): array
    {
        $system = (string) file_get_contents(resource_path('prompts/preflight-classify.md'));

        $lines = [];
        foreach ($history as $message) {
            $content = trim((string) ($message['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            $lines[] = (($message['role'] ?? 'user') === 'assistant' ? 'Estúdio' : 'Lead').': '.$content;
        }

        $content = $this->ai->chat([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => "CONTEXTO:\n".$context
                ."\n\nHISTÓRICO RECENTE (mais antigo primeiro):\n".($lines === [] ? '_(sem histórico)_' : implode("\n", $lines))
                ."\n\nMENSAGEM DO LEAD:\n".$leadText],
        ], $this->model);

        $parsed = Json::parse($content);
        $confidence = isset($parsed['confidence']) ? (float) $parsed['confidence'] : 0.0;

        return [
            'intent' => is_string($parsed['intent'] ?? null) ? $parsed['intent'] : 'other',
            'entities' => is_array($parsed['entities'] ?? null) ? $parsed['entities'] : [],
            'flags' => is_array($parsed['flags'] ?? null) ? $parsed['flags'] : [],
            'confidence' => max(0.0, min(1.0, $confidence)),
        ];
    }
}
