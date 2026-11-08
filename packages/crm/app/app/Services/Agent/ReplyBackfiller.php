<?php

namespace App\Services\Agent;

use App\Jobs\CompareDraft;
use App\Models\AgentDraft;
use App\Models\Artist;
use App\Services\WahaService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Import the artist's historical WhatsApp replies from the WAHA NOWEB store
 * and pair them with the drafts that were waiting for an answer.
 *
 * Only `fromMe` + `source=app` messages are imported; messages the CRM itself
 * sent through the API (`source=api`) are skipped.
 */
class ReplyBackfiller
{
    public function __construct(
        private readonly WahaService $waha,
        private readonly AgentStore $store,
        private readonly DraftStore $drafts,
    ) {
    }

    /**
     * @return array<string, int|bool>
     */
    public function backfill(Artist $artist, int $days = 7, bool $dryRun = false, bool $compare = true): array
    {
        $since = now()->subDays(max(1, $days));
        $chatIds = $this->chatIds($artist, $since);

        $result = [
            'dry_run' => $dryRun,
            'chats' => $chatIds->count(),
            'chats_without_history' => 0,
            'messages_found' => 0,
            'imported' => 0,
            'duplicates' => 0,
            'paired' => 0,
            'without_draft' => 0,
        ];

        foreach ($chatIds as $chatId) {
            $jid = Str::contains($chatId, ':') ? Str::after($chatId, ':') : $chatId;

            if ($jid === '') {
                continue;
            }

            $messages = $this->waha->chatMessages($artist, $jid, 200);

            if ($messages === null) {
                $result['chats_without_history']++;

                continue;
            }

            $artistMessages = collect($messages)
                ->filter(function ($message) use ($since) {
                    if (! is_array($message) || ($message['fromMe'] ?? false) !== true) {
                        return false;
                    }

                    if (($message['source'] ?? 'app') === 'api') {
                        return false;
                    }

                    $timestamp = (int) ($message['timestamp'] ?? 0);

                    return $timestamp > 0
                        && $timestamp >= $since->timestamp
                        && trim((string) ($message['body'] ?? '')) !== '';
                })
                ->sortBy(fn ($message) => (int) $message['timestamp'])
                ->values();

            $result['messages_found'] += $artistMessages->count();

            foreach ($artistMessages as $message) {
                $waId = trim((string) ($message['id'] ?? ''));

                if ($waId === '') {
                    continue;
                }

                if ($dryRun) {
                    $result['imported']++;

                    continue;
                }

                $at = Carbon::createFromTimestamp((int) $message['timestamp'])->toIso8601String();
                $imported = $this->store->importArtistMessage(
                    $artist->id,
                    $chatId,
                    trim((string) $message['body']),
                    $waId,
                    $at,
                );

                $imported ? $result['imported']++ : $result['duplicates']++;
            }

            foreach ($this->bursts($artistMessages) as $burst) {
                $firstAt = Carbon::createFromTimestamp((int) $burst[0]['timestamp']);
                $lastAt = Carbon::createFromTimestamp((int) $burst[count($burst) - 1]['timestamp']);
                $text = trim(implode("\n", array_map(
                    fn ($message) => trim((string) $message['body']),
                    $burst,
                )));

                $draft = AgentDraft::query()
                    ->where('artist_id', $artist->id)
                    ->where('chat_id', $chatId)
                    ->whereNull('artist_reply')
                    ->whereIn('status', [AgentDraft::STATUS_PENDING, AgentDraft::STATUS_REPLIED])
                    ->where('created_at', '<=', $firstAt)
                    ->orderByDesc('created_at')
                    ->first();

                if ($draft === null) {
                    $result['without_draft']++;

                    continue;
                }

                if ($dryRun) {
                    $result['paired']++;

                    continue;
                }

                $this->drafts->attachArtistReplyAt($draft, $text, $lastAt);
                $result['paired']++;

                if ($compare) {
                    CompareDraft::dispatch((string) $draft->id)
                        ->onQueue((string) config('agent.queue', 'agent'));
                }
            }
        }

        return $result;
    }

    /**
     * @return \Illuminate\Support\Collection<int, string>
     */
    private function chatIds(Artist $artist, Carbon $since)
    {
        $drafts = AgentDraft::query()
            ->where('artist_id', $artist->id)
            ->where('created_at', '>=', $since)
            ->pluck('chat_id');

        try {
            $sessions = DB::table('chat_sessions')
                ->where('artist_id', $artist->id)
                ->pluck('chat_id');
        } catch (\Throwable $e) {
            // The lightweight SQLite test schema may not have chat_sessions.
            $sessions = collect();
        }

        return $drafts
            ->merge($sessions)
            ->filter(fn ($id) => is_string($id) && $id !== '')
            ->unique()
            ->values();
    }

    /**
     * Group consecutive artist bubbles (<= 5 minutes apart) into one answer.
     *
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $messages
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function bursts($messages): array
    {
        $bursts = [];
        $current = [];
        $previous = null;

        foreach ($messages as $message) {
            $timestamp = (int) $message['timestamp'];

            if ($current !== [] && $previous !== null && $timestamp - $previous > 300) {
                $bursts[] = $current;
                $current = [];
            }

            $current[] = $message;
            $previous = $timestamp;
        }

        if ($current !== []) {
            $bursts[] = $current;
        }

        return $bursts;
    }
}
