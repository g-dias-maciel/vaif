<?php

namespace App\Services\Agent;

use App\Models\Artist;
use App\Models\Lead;

/**
 * Builds the artist-facing conversation summary from the stored transcript.
 *
 * The summary is a convenience, not a record: it is replaced in place on every
 * quiet window. It never includes internal classification or assumptions.
 */
class ConversationSummarizer
{
    public function __construct(
        private readonly AiClient $ai,
        private readonly AgentStore $store,
    ) {}

    /**
     * @return array{body: string, message_count: int, model: string}|null
     */
    public function summarize(Artist $artist, Lead $lead): ?array
    {
        $limit = (int) config('agent.observe.summary_messages', 40);
        $history = $this->store->history((string) $lead->id, max(4, $limit));

        $lines = [];
        foreach ($history as $message) {
            $content = trim((string) ($message['content'] ?? ''));

            if ($content === '') {
                continue;
            }

            $speaker = ($message['role'] ?? 'user') === 'assistant' ? 'Estúdio' : 'Lead';
            $lines[] = $speaker.': '.$content;
        }

        // One message is not a conversation.
        if (count($lines) < 2) {
            return null;
        }

        $system = (string) file_get_contents(resource_path('prompts/summarize-conversation.md'));
        $model = (string) config('agent.models.summary');

        $content = $this->ai->chat([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => 'CONVERSA (mais antiga primeiro):'."\n".implode("\n", $lines)],
        ], $model);

        $body = trim((string) $content);

        if ($body === '') {
            return null;
        }

        return [
            'body' => mb_substr($body, 0, 2000),
            'message_count' => count($lines),
            'model' => $model,
        ];
    }
}
