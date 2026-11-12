<?php

namespace App\Services\Agent;

use App\Models\ObservedWrite;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Postgres implementation of observe mode persistence.
 *
 * The domain write goes through `observe_project_fields`, a SECURITY DEFINER
 * RPC. The transcript itself is written by `AgentStore::rememberHuman` and by
 * the webhook's artist-message capture; this class only records the interpreted
 * turn and the summary.
 */
class DatabaseObservedStore implements ObservedStore
{
    public function saveTurn(array $turn): bool
    {
        $occurred = $this->normalizeTimestamp($turn['occurred_at'] ?? null);

        $inserted = DB::table('observed_turns')->insertOrIgnore([
            'id' => (string) ($turn['id'] ?? Str::uuid()),
            'artist_id' => $turn['artist_id'],
            'lead_id' => $turn['lead_id'] ?? null,
            'project_id' => $turn['project_id'] ?? null,
            'chat_id' => $turn['chat_id'],
            'direction' => $turn['direction'],
            'wa_message_id' => $turn['wa_message_id'] ?? null,
            'occurred_at' => $occurred ?? now(),
            'text' => (string) ($turn['text'] ?? ''),
            'text_hash' => hash('sha256', (string) ($turn['text'] ?? '')),
            'intent' => $turn['intent'] ?? null,
            'entities' => $this->json($turn['entities'] ?? []),
            'facts' => $this->json($turn['facts'] ?? []),
            'model' => $turn['model'] ?? null,
            'status' => $turn['status'] ?? 'observed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $inserted > 0;
    }

    public function applyFields(
        string $artistId,
        string $projectId,
        string $phone,
        array $entities,
        string $origin = 'artist',
    ): array {
        try {
            $row = DB::selectOne(
                'select observe_project_fields(?::uuid, ?::uuid, ?, ?::jsonb, ?) as result',
                [$artistId, $projectId, $phone, $this->json($entities), $origin]
            );

            return $this->decode($row->result ?? null);
        } catch (\Throwable $e) {
            // An unmigrated deployment must still keep the transcript.
            report($e);

            return ['error' => 'observe_project_fields_failed'];
        }
    }

    public function createWrite(array $write): ?ObservedWrite
    {
        try {
            return ObservedWrite::query()->firstOrCreate(
                [
                    'artist_id' => $write['artist_id'],
                    'dedupe_key' => $write['dedupe_key'],
                ],
                [
                    'lead_id' => $write['lead_id'] ?? null,
                    'project_id' => $write['project_id'] ?? null,
                    'chat_id' => $write['chat_id'] ?? null,
                    'observed_turn_id' => $write['observed_turn_id'] ?? null,
                    'kind' => $write['kind'],
                    'status' => $write['status'] ?? ObservedWrite::STATUS_PENDING,
                    'confidence' => $write['confidence'] ?? null,
                    'payload' => $write['payload'] ?? [],
                    'evidence' => $write['evidence'] ?? [],
                ],
            );
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    public function writeQuote(
        string $artistId,
        string $projectId,
        int $baseCents,
        int $offeredCents,
        string $actor = 'artist',
    ): ?array {
        try {
            $rows = DB::select(
                'select * from write_quote(?::uuid, ?::uuid, ?::integer, ?::integer, null::numeric, null::integer, null::timestamptz, ?)',
                [$artistId, $projectId, $baseCents, $offeredCents, $actor]
            );

            return $rows === [] ? null : (array) $rows[0];
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    public function requestDeposit(
        string $artistId,
        string $projectId,
        int $amountCents,
        ?string $method = null,
        string $actor = 'artist',
    ): ?array {
        try {
            $rows = DB::select(
                'select * from request_deposit(?::uuid, ?::uuid, ?::integer, ?, ?)',
                [$artistId, $projectId, $amountCents, $method, $actor]
            );

            return $rows === [] ? null : (array) $rows[0];
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    public function confirmDeposit(
        string $artistId,
        string $projectId,
        string $method = 'pix',
        ?string $externalRef = null,
        string $actor = 'artist',
    ): ?array {
        try {
            $rows = DB::select(
                'select * from confirm_deposit(?::uuid, ?::uuid, ?, ?, ?)',
                [$artistId, $projectId, $method, $externalRef, $actor]
            );

            return $rows === [] ? null : (array) $rows[0];
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    public function setStage(
        string $artistId,
        string $projectId,
        string $stageKey,
        string $actor = 'artist',
        ?string $at = null,
    ): void {
        try {
            if ($at !== null && $at !== '' && DB::connection()->getDriverName() === 'pgsql') {
                DB::select(
                    'select backfill_project_stage(?::uuid, ?::uuid, ?, ?::timestamptz, ?)',
                    [$artistId, $projectId, $stageKey, $at, 'system'],
                );

                return;
            }

            DB::select('select * from set_project_stage(?::uuid, ?::uuid, ?, ?)', [
                $artistId,
                $projectId,
                $stageKey,
                $actor,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function importMessage(
        string $leadId,
        string $direction,
        string $text,
        ?string $waMessageId = null,
        ?string $at = null,
        bool $artist = false,
    ): bool {
        $text = trim($text);

        if ($text === '') {
            return false;
        }

        $type = $direction === 'outbound' ? 'ai' : 'human';
        $at = $this->normalizeTimestamp($at) ?? now()->toIso8601String();

        try {
            if ($waMessageId !== null && $waMessageId !== '') {
                $exists = DB::table('agent_memory.chat_memory')
                    ->where('session_id', $leadId)
                    ->whereRaw("message->'additional_kwargs'->>'wa_message_id' = ?", [$waMessageId])
                    ->exists();
            } else {
                $exists = DB::table('agent_memory.chat_memory')
                    ->where('session_id', $leadId)
                    ->whereRaw("message->>'type' = ?", [$type])
                    ->whereRaw("message->>'content' = ?", [$text])
                    ->whereBetween('created_at', [
                        Carbon::parse($at)->subSeconds(5),
                        Carbon::parse($at)->addSeconds(5),
                    ])
                    ->exists();
            }

            if ($exists) {
                return false;
            }

            $kwargs = [];

            if ($artist) {
                $kwargs['author'] = 'artist';
            }

            if ($waMessageId !== null && $waMessageId !== '') {
                $kwargs['wa_message_id'] = $waMessageId;
            }

            $kwargs['backfill'] = true;

            DB::table('agent_memory.chat_memory')->insert([
                'session_id' => $leadId,
                'message' => $this->json([
                    'type' => $type,
                    'content' => $text,
                    'additional_kwargs' => $kwargs,
                    'response_metadata' => [],
                    'tool_calls' => [],
                    'invalid_tool_calls' => [],
                ]),
                'created_at' => $at,
            ]);

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    public function setLeadTimeline(
        string $leadId,
        ?string $firstSeenAt = null,
        ?string $lastMessageAt = null,
    ): void {
        try {
            $updates = [];

            if ($firstSeenAt !== null) {
                $updates['first_seen_at'] = Carbon::parse($firstSeenAt);
            }

            if ($lastMessageAt !== null) {
                $updates['last_message_at'] = Carbon::parse($lastMessageAt);
            }

            if ($updates !== []) {
                DB::table('leads')->where('id', $leadId)->update($updates);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function setBookingSync(string $bookingId, bool $pending): void
    {
        try {
            DB::table('calendar_entries')->where('id', $bookingId)->update([
                'google_sync_pending' => $pending,
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function labelBooking(string $bookingId, string $label): void
    {
        if ($bookingId === '') {
            return;
        }

        try {
            DB::table('calendar_entries')->where('id', $bookingId)->update([
                'created_by' => $label,
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function setBookingCreatedAt(
        string $bookingId,
        ?string $createdAt,
        bool $sync,
        string $label = 'backfill',
    ): void {
        if ($bookingId === '') {
            return;
        }

        try {
            $updates = [
                'google_sync_pending' => $sync,
                'created_by' => $label,
                'updated_at' => now(),
            ];

            if ($createdAt !== null) {
                $updates['created_at'] = Carbon::parse($createdAt);
            }

            DB::table('calendar_entries')->where('id', $bookingId)->update($updates);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function latestMessageAt(string $leadId): ?string
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return null;
        }

        try {
            $value = DB::table('agent_memory.chat_memory')
                ->where('session_id', $leadId)
                ->max('created_at');
        } catch (\Throwable $e) {
            return null;
        }

        return $value !== null ? (string) $value : null;
    }

    public function upsertSummary(
        string $leadId,
        string $body,
        int $messageCount,
        ?string $model = null,
    ): void {
        $now = now();

        DB::table('lead_summaries')->upsert([
            [
                'lead_id' => $leadId,
                'body' => $body,
                'message_count' => $messageCount,
                'model' => $model,
                'generated_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ], ['lead_id'], ['body', 'message_count', 'model', 'generated_at', 'updated_at']);
    }

    private function normalizeTimestamp(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('c');
        }

        $normalized = preg_replace('/([+-]\d{2})$/', '$1:00', (string) $value) ?? (string) $value;

        try {
            return Carbon::parse($normalized)->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
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
}
