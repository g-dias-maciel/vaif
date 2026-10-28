<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Read-only view of n8n's LangChain memory (`agent_memory.chat_memory`).
 *
 * One row per message, keyed by the Lead id (`session_id`). The app only reads
 * it for the conversation overview on the lead page; n8n owns the writes.
 */
class ChatMessage extends Model
{
    protected $table = 'agent_memory.chat_memory';

    protected $primaryKey = 'id';

    public $incrementing = true;

    public $timestamps = false;

    protected $casts = [
        'message' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * `human` (lead) or `ai` (Beatriz/SDR).
     */
    public function getMessageTypeAttribute(): string
    {
        return (string) ($this->message['type'] ?? '');
    }

    public function getDirectionAttribute(): string
    {
        return $this->message_type === 'human' ? 'inbound' : 'outbound';
    }

    /**
     * Human messages carry the `[Contexto: ...]` prefix the agent uses; strip
     * it for display. Content may be a string or LangChain content blocks.
     */
    public function getContentAttribute(): string
    {
        $content = $this->message['content'] ?? '';

        if (is_array($content)) {
            $parts = [];

            foreach ($content as $block) {
                if (is_string($block)) {
                    $parts[] = $block;
                } elseif (is_array($block)) {
                    $parts[] = $block['text'] ?? $block['content'] ?? '';
                }
            }

            $content = implode("\n", array_filter($parts));
        }

        $content = trim((string) $content);

        if ($this->message_type === 'human') {
            // The agent prefixes every inbound message with its context and
            // the artist marker. Neither belongs in the conversation view.
            $content = preg_replace('/^\[Contexto:.*?\]\s*/s', '', $content) ?? $content;
            $content = preg_replace('/^\[artist_id=[^\]]*\]\s*/i', '', $content) ?? $content;
        }

        return trim($content);
    }
}
