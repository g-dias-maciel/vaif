<?php

namespace App\Services\Agent;

class ClaimExtractor
{
    public function __construct(
        private readonly AiClient $ai,
        private readonly string $model,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function extract(string $reply, array $facts): array
    {
        $system = (string) file_get_contents(resource_path('prompts/extract-claims.md'));

        $content = $this->ai->chat([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => "FATOS:\n".Json::string($facts)."\n\nRESPOSTA DA BEATRIZ:\n".$reply],
        ], $this->model);

        $parsed = Json::parse($content);
        $claims = $parsed['claims'] ?? [];

        if (! is_array($claims)) {
            return [];
        }

        return array_values(array_filter($claims, fn ($claim) => is_array($claim) && isset($claim['type'])));
    }
}
