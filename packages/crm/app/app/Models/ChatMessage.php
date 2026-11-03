<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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

    public function getCreatedAtAttribute($value): ?Carbon
    {
        return $value ? Carbon::parse($value)->setTimezone(config('app.timezone')) : null;
    }

    /**
     * Record a message the CRM sends outside n8n (for example the deposit
     * confirmation) so it appears in the Lead's conversation panel and in the
     * agent's memory. n8n owns this table; the CRM only has INSERT on it.
     */
    public static function recordOutbound(string $leadId, string $text): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return; // agent_memory is Postgres-only (tests use SQLite).
        }

        try {
            DB::table('agent_memory.chat_memory')->insert([
                'session_id' => $leadId,
                'message' => json_encode([
                    'type' => 'ai',
                    'content' => $text,
                    'tool_calls' => [],
                    'additional_kwargs' => (object) [],
                    'response_metadata' => (object) [],
                    'invalid_tool_calls' => [],
                ], JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\Throwable $e) {
            // A transcript write must never break the WhatsApp confirmation.
            report($e);
        }
    }

    /**
     * Latest message time per Lead, for the dashboard cards.
     *
     * @param  array<int, string>  $leadIds
     * @return array<string, Carbon>
     */
    public static function latestTimesForLeads(array $leadIds): array
    {
        if ($leadIds === [] || DB::connection()->getDriverName() !== 'pgsql') {
            return [];
        }

        try {
            $rows = DB::table('agent_memory.chat_memory')
                ->select('session_id', DB::raw('max(created_at) as last_at'))
                ->whereIn('session_id', $leadIds)
                ->groupBy('session_id')
                ->get();

            $times = [];
            foreach ($rows as $row) {
                if ($row->last_at) {
                    $times[(string) $row->session_id] = Carbon::parse($row->last_at)
                        ->setTimezone(config('app.timezone'));
                }
            }

            return $times;
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

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
     * Messages sent by the artist from their own WhatsApp are stored as `ai`
     * with `additional_kwargs.author = 'artist'`.
     */
    public function getIsArtistAttribute(): bool
    {
        $extra = $this->message['additional_kwargs'] ?? [];

        return is_array($extra) && ($extra['author'] ?? null) === 'artist';
    }

    public function getSenderLabelAttribute(): string
    {
        if ($this->message_type === 'human') {
            return 'Cliente';
        }

        return $this->is_artist ? 'Artista' : 'Beatriz';
    }

    public function getIsToolAttribute(): bool
    {
        return $this->message_type === 'tool';
    }

    /**
     * Tool envelopes and the empty `[]` AI rows are internal LangChain
     * bookkeeping, not messages the Artist should see.
     */
    public function getIsDisplayableAttribute(): bool
    {
        if (in_array($this->message_type, ['tool', 'system'], true)) {
            return false;
        }

        if ($this->message_type !== 'ai') {
            return true;
        }

        $content = $this->message['content'] ?? '';

        if (is_array($content)) {
            return $content !== [];
        }

        return trim((string) $content) !== '[]';
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
