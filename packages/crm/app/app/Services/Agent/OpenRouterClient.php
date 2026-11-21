<?php

namespace App\Services\Agent;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class OpenRouterClient implements AiClient
{
    public function chat(array $messages, string $model): string
    {
        return $this->complete($messages, $model, [
            'max_tokens' => (int) config('agent.max_tokens', 2000),
        ]);
    }

    /**
     * Multimodal / custom-parameter call used by audio transcription and the
     * reference-image analysis.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<string, mixed>  $options
     */
    public function complete(array $messages, string $model, array $options = []): string
    {
        $key = (string) config('agent.openrouter.key');
        $url = (string) config('agent.openrouter.url');
        $timeout = (int) ($options['_timeout'] ?? config('agent.openrouter.timeout', 30));
        unset($options['_timeout']);

        if ($key === '') {
            throw new AgentException('OPENROUTER_API_KEY is not configured.');
        }

        try {
            $response = Http::withToken($key)
                ->acceptJson()
                ->timeout($timeout)
                ->retry((int) config('agent.openrouter.retries', 2), 300)
                ->post($url.'/chat/completions', array_merge([
                    'model' => $model,
                    'temperature' => 0,
                    'messages' => $messages,
                ], $options));
        } catch (ConnectionException $e) {
            throw new AgentException('OpenRouter connection failed: '.$e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            throw new AgentException('OpenRouter returned HTTP '.$response->status().': '.mb_substr($response->body(), 0, 500));
        }

        $content = $response->json('choices.0.message.content');

        if (! is_string($content) || trim($content) === '') {
            throw new AgentException('OpenRouter returned an empty message.');
        }

        if ($response->json('choices.0.finish_reason') === 'length') {
            throw new AgentException(
                'OpenRouter stopped at the output limit (finish_reason=length). Increase max_tokens or shorten the prompt.'
            );
        }

        return $content;
    }
}
