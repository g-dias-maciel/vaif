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
     * @return array{intent: string, entities: array, flags: array, confidence: float}
     */
    public function classify(string $context, string $leadText): array
    {
        $system = (string) file_get_contents(resource_path('prompts/preflight-classify.md'));

        $content = $this->ai->chat([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => "CONTEXTO:\n".$context."\n\nMENSAGEM DO LEAD:\n".$leadText],
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
