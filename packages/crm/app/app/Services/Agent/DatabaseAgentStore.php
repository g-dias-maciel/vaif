<?php

namespace App\Services\Agent;

use Illuminate\Support\Facades\DB;

/**
 * Postgres implementation. All domain access goes through the v2 RPCs.
 */
class DatabaseAgentStore implements AgentStore
{
    public function startTurn(string $artistId, string $chatId, string $phone, ?string $name, string $source = 'whatsapp'): array
    {
        $row = DB::selectOne(
            'select * from start_turn(?::uuid, ?, ?, ?, ?)',
            [$artistId, $chatId, $phone, $name, $source]
        );

        return $this->normalize($row);
    }

    public function facts(string $artistId, string $projectId, string $phone, ?string $intent, array $entities = []): array
    {
        $row = DB::selectOne(
            'select build_turn_facts(?::uuid, ?::uuid, ?, ?, ?::jsonb) as facts',
            [$artistId, $projectId, $phone, $intent, $this->json($entities)]
        );

        return $this->decode($row->facts ?? null);
    }

    public function bookSlot(string $artistId, string $projectId, string $startAt, int $durationMin = 120): ?array
    {
        $rows = DB::select(
            'select * from book_slot(?::uuid, ?::uuid, ?::timestamptz, ?)',
            [$artistId, $projectId, $startAt, $durationMin]
        );

        return $rows === [] ? null : $this->normalize($rows[0]);
    }

    public function reschedule(string $artistId, string $bookingId, string $startAt): ?array
    {
        $rows = DB::select(
            'select * from reschedule_booking(?::uuid, ?::uuid, ?::timestamptz)',
            [$artistId, $bookingId, $startAt]
        );

        return $rows === [] ? null : $this->normalize($rows[0]);
    }

    public function apply(array $p): array
    {
        $row = DB::selectOne(
            'select apply_grounded_turn_once(
                ?::uuid, ?::uuid, ?::uuid, ?, ?::uuid, ?,
                ?::jsonb, ?::jsonb, ?::jsonb, ?::jsonb, ?, ?,
                ?::jsonb, ?, ?::numeric
             ) as result',
            [
                $p['artist_id'], $p['lead_id'], $p['project_id'], $p['chat_id'], $p['turn_id'],
                $p['intent'], $this->json($p['flags'] ?? []), $this->json($p['entities'] ?? []),
                $this->json($p['facts'] ?? []), $this->json($p['claims'] ?? []), $p['reply'],
                $p['resolution'] ?? 'accepted', $this->json($p['offered_slots'] ?? []),
                $p['lead_text'] ?? '', $p['confidence'] ?? 0,
            ]
        );

        return $this->decode($row->result ?? null);
    }

    public function log(array $p): void
    {
        DB::selectOne(
            'select log_agent_turn(
                ?::uuid, ?::uuid, ?::uuid, ?::uuid, ?, ?, ?, ?::numeric,
                ?::jsonb, ?, ?::jsonb, ?::jsonb, ?, ?, ?::integer, ?::boolean, ?::jsonb
             )',
            [
                $p['turn_id'], $p['artist_id'], $p['lead_id'], $p['project_id'], $p['chat_id'],
                $p['mode'] ?? 'enforce', $p['intent'] ?? null, $p['confidence'] ?? null,
                $this->json($p['facts'] ?? []), $p['reply'] ?? '', $this->json($p['claims'] ?? []),
                $this->json($p['violations'] ?? []), $p['resolution'] ?? null, $p['model'] ?? null,
                $p['latency_ms'] ?? null, $p['repaired'] ?? false,
                $this->json($p['original_violations'] ?? []),
            ]
        );
    }

    public function remember(string $leadId, string $leadText, string $reply): void
    {
        $human = $this->json([
            'type' => 'human',
            'content' => $leadText,
            'additional_kwargs' => [],
            'response_metadata' => [],
            'tool_calls' => [],
            'invalid_tool_calls' => [],
        ]);
        $ai = $this->json([
            'type' => 'ai',
            'content' => $reply,
            'tool_calls' => [],
            'additional_kwargs' => [],
            'response_metadata' => [],
            'invalid_tool_calls' => [],
        ]);

        DB::statement(
            'insert into agent_memory.chat_memory (session_id, message) values (?, ?::jsonb), (?, ?::jsonb)',
            [$leadId, $human, $leadId, $ai]
        );
    }

    public function history(string $leadId, int $limit = 8): array
    {
        $rows = DB::select(
            'select message from agent_memory.chat_memory where session_id = ? order by id desc limit ?',
            [$leadId, $limit]
        );

        $out = [];
        foreach (array_reverse($rows) as $row) {
            $message = $this->decode($row->message ?? null);
            $type = $message['type'] ?? null;
            $content = (string) ($message['content'] ?? '');

            if ($content === '') {
                continue;
            }

            if ($type === 'human') {
                $content = preg_replace('/^\[Contexto:.*?\]\s*(\[artist_id=[^\]]+\]\s*)?/s', '', $content) ?? $content;
                $out[] = ['role' => 'user', 'content' => trim($content)];
            } elseif ($type === 'ai') {
                $out[] = ['role' => 'assistant', 'content' => trim($content)];
            }
        }

        return $out;
    }

    public function chatOwner(string $artistId, string $chatId): string
    {
        $owner = DB::table('chat_sessions')
            ->where('artist_id', $artistId)
            ->where('chat_id', $chatId)
            ->value('owner');

        return $owner ?: 'agent';
    }

    public function takeControl(string $artistId, string $chatId, ?string $leadId = null): void
    {
        DB::selectOne(
            'select take_chat_control(?::uuid, ?, ?::uuid)',
            [$artistId, $chatId, $leadId]
        );
    }

    public function recordArtistMessage(string $artistId, string $chatId, string $text): void
    {
        $message = $this->json([
            'type' => 'ai',
            'content' => $text,
            'additional_kwargs' => ['author' => 'artist'],
            'response_metadata' => [],
            'tool_calls' => [],
            'invalid_tool_calls' => [],
        ]);

        DB::statement(
            'insert into agent_memory.chat_memory (session_id, message)
             select cs.lead_id, ?::jsonb
               from chat_sessions cs
              where cs.artist_id = ?::uuid and cs.chat_id = ?',
            [$message, $artistId, $chatId]
        );
    }

    public function bufferAccumulate(string $artistId, string $chatId, string $text): array
    {
        $rows = DB::select(
            'select * from chat_buffer_accumulate(?::uuid, ?, ?, now())',
            [$artistId, $chatId, $text]
        );

        return $rows === [] ? [] : $this->normalize($rows[0]);
    }

    public function bufferTyping(string $artistId, string $chatId, string $state): void
    {
        DB::selectOne(
            'select chat_buffer_typing(?::uuid, ?, ?, now())',
            [$artistId, $chatId, $state]
        );
    }

    public function bufferState(string $artistId, string $chatId): ?array
    {
        $rows = DB::select(
            'select * from chat_buffer_state(?::uuid, ?)',
            [$artistId, $chatId]
        );

        return $rows === [] ? null : $this->normalize($rows[0]);
    }

    public function bufferClear(string $artistId, string $chatId): void
    {
        DB::selectOne(
            'select chat_buffer_clear(?::uuid, ?)',
            [$artistId, $chatId]
        );
    }

    public function patchChatState(string $artistId, string $chatId, array $patch): void
    {
        DB::selectOne(
            'select patch_chat_state(?::uuid, ?, ?::jsonb)',
            [$artistId, $chatId, $this->json($patch)]
        );
    }

    public function logDrop(?string $artistId, string $session, ?string $phone, ?string $chatId, string $reason, array $payload = []): void
    {
        DB::selectOne(
            'select log_ingest_drop(?::uuid, ?, ?, ?, ?, ?::jsonb)',
            [$artistId, $session, $phone, $chatId, $reason, $this->json($payload)]
        );
    }

    private function json(mixed $value): string
    {
        return json_encode($value ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function normalize(mixed $row): array
    {
        if ($row === null) {
            return [];
        }

        $data = (array) $row;

        foreach (['agent_state', 'reference_analysis'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = $this->decode($data[$field]);
            }
        }

        return $data;
    }
}
