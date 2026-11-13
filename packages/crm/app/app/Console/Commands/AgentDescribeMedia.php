<?php

namespace App\Console\Commands;

use App\Jobs\DescribeArtistMedia;
use App\Models\AgentDraft;
use App\Models\Artist;
use App\Services\Agent\AgentMedia;
use App\Services\Agent\MessageText;
use App\Services\WahaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Bulk-describe artist media (images and voice notes) and feed the result into
 * the calibration judge.
 *
 * WAHA only exposes media URLs when the history request asks for them, so the
 * command fetches each chat once with `downloadMedia=true`, maps the message
 * ids already stored in `agent_memory`, and dispatches one description job per
 * draft. Video is skipped (the vision model only accepts images).
 */
class AgentDescribeMedia extends Command
{
    protected $signature = 'agent:describe-media
        {artist? : Artist UUID or WAHA session slug}
        {--all : Every artist with media-only drafts}
        {--days=7 : Only drafts replied in this window}
        {--limit=200 : Maximum drafts per artist}
        {--sync : Describe in this process instead of the mock queue}
        {--dry-run : Count without downloading or describing}';

    protected $description = 'Describe artist images/audio and re-run the calibration comparison';

    public function handle(WahaService $waha, AgentMedia $media): int
    {
        $days = max(1, (int) $this->option('days'));
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');

        $artists = $this->artists($days);

        if ($artists->isEmpty()) {
            $this->warn('No artist with media-only drafts in this window.');

            return self::SUCCESS;
        }

        $totals = [
            'drafts' => 0,
            'with_media' => 0,
            'dispatched' => 0,
            'no_url' => 0,
            'skipped_video' => 0,
            'chats_without_history' => 0,
        ];

        foreach ($artists as $artist) {
            $result = $this->processArtist($artist, $waha, $media, $days, $limit, $dryRun);

            foreach ($totals as $key => $value) {
                $totals[$key] = $value + ($result[$key] ?? 0);
            }

            $this->table(['metric', 'value'], [
                ['artist', $artist->name],
                ['media-only drafts', $result['drafts']],
                ['with media URL', $result['with_media']],
                ['dispatched', $result['dispatched']],
                ['without URL', $result['no_url']],
                ['video only (staff note)', $result['skipped_video']],
                ['chats without history', $result['chats_without_history']],
            ]);
        }

        if (count($artists) > 1) {
            $this->table(['metric', 'value'], [
                ['artists', $artists->count()],
                ['media-only drafts', $totals['drafts']],
                ['with media URL', $totals['with_media']],
                ['dispatched', $totals['dispatched']],
                ['without URL', $totals['no_url']],
                ['video only (staff note)', $totals['skipped_video']],
                ['chats without history', $totals['chats_without_history']],
            ]);
        }

        if ($dryRun) {
            $this->warn('Dry run: nothing was downloaded or written.');
        }

        return self::SUCCESS;
    }

    /**
     * @return \Illuminate\Support\Collection<int, Artist>
     */
    private function artists(int $days)
    {
        if ($this->option('all')) {
            $ids = AgentDraft::query()
                ->where('source', '!=', AgentDraft::SOURCE_SIMULATED)
                ->whereNotNull('artist_reply')
                ->whereNull('artist_media_note')
                ->where('artist_replied_at', '>=', now()->subDays($days))
                ->distinct()
                ->pluck('artist_id');

            return Artist::query()->whereIn('id', $ids)->orderBy('name')->get();
        }

        $key = (string) $this->argument('artist');

        if ($key === '') {
            return collect();
        }

        $query = Artist::query()->where('wa_session_slug', $key);

        if (Str::isUuid($key)) {
            $query->orWhere('id', $key);
        }

        $artist = $query->first();

        return $artist !== null ? collect([$artist]) : collect();
    }

    /**
     * @return array<string, int>
     */
    private function processArtist(
        Artist $artist,
        WahaService $waha,
        AgentMedia $media,
        int $days,
        int $limit,
        bool $dryRun,
    ): array {
        $drafts = AgentDraft::query()
            ->where('artist_id', $artist->id)
            ->where('source', '!=', AgentDraft::SOURCE_SIMULATED)
            ->whereIn('status', [AgentDraft::STATUS_REPLIED, AgentDraft::STATUS_COMPARED])
            ->whereNotNull('artist_reply')
            ->whereNotNull('artist_replied_at')
            ->whereNull('artist_media_note')
            ->where('artist_replied_at', '>=', now()->subDays($days))
            ->where(function ($query) {
                foreach (['[MÍDIA%', '[FOTO%', '[VÍDEO%', '[ÁUDIO%', '[STICKER%', '[DOCUMENTO%'] as $marker) {
                    $query->orWhere('artist_reply', 'like', $marker);
                }
            })
            ->orderBy('artist_replied_at')
            ->limit($limit)
            ->get()
            ->filter(fn (AgentDraft $draft) => MessageText::isMediaOnly((string) $draft->artist_reply))
            ->values();

        $result = [
            'drafts' => $drafts->count(),
            'with_media' => 0,
            'dispatched' => 0,
            'no_url' => 0,
            'skipped_video' => 0,
            'chats_without_history' => 0,
        ];

        foreach ($drafts->groupBy('chat_id') as $chatId => $chatDrafts) {
            $jid = Str::contains((string) $chatId, ':') ? Str::after((string) $chatId, ':') : (string) $chatId;

            if ($jid === '') {
                $result['no_url'] += $chatDrafts->count();

                continue;
            }

            $messages = $waha->chatMessages($artist, $jid, 300, true);

            if ($messages === null) {
                $result['chats_without_history']++;
                $result['no_url'] += $chatDrafts->count();

                continue;
            }

            $byId = [];
            $byTimestamp = [];

            foreach ($messages as $message) {
                if (! is_array($message) || ($message['fromMe'] ?? false) !== true) {
                    continue;
                }

                $mediaUrl = (string) ($message['media']['url'] ?? '');

                if (($message['hasMedia'] ?? false) !== true || $mediaUrl === '') {
                    continue;
                }

                $id = trim((string) ($message['id'] ?? ''));

                $item = [
                    'url' => $mediaUrl,
                    'mime' => (string) ($message['media']['mimetype'] ?? ''),
                    'id' => $id,
                ];

                if ($id !== '') {
                    $byId[$id] = $item;
                }

                $byTimestamp[] = [
                    'timestamp' => (int) ($message['timestamp'] ?? 0),
                    'item' => $item,
                ];
            }

            foreach ($chatDrafts as $draft) {
                $mediaItems = $this->mediaForDraft($draft, $byId, $byTimestamp);

                if ($mediaItems === []) {
                    $result['no_url']++;

                    continue;
                }

                // The vision model handles images; audio is transcribed.
                // Video is skipped and left for a staff note.
                $mediaItems = array_values(array_filter(
                    $mediaItems,
                    fn (array $item) => str_starts_with((string) ($item['mime'] ?? ''), 'image/')
                        || str_starts_with((string) ($item['mime'] ?? ''), 'audio/')
                ));

                if ($mediaItems === []) {
                    $result['skipped_video']++;

                    continue;
                }

                $result['with_media']++;

                if ($dryRun) {
                    continue;
                }

                $job = new DescribeArtistMedia((string) $draft->id, $mediaItems);

                if ($this->option('sync')) {
                    $job->handle($media);
                } else {
                    $job->onQueue((string) config('agent.mock_queue', 'mock'));
                    dispatch($job);
                }

                $result['dispatched']++;
            }
        }

        return $result;
    }

    /**
     * @param  array<string, array{url: string, mime: string}>  $byId
     * @param  array<int, array{timestamp: int, item: array{url: string, mime: string}}>  $byTimestamp
     * @return array<int, array{url: string, mime: string}>
     */
    private function mediaForDraft(AgentDraft $draft, array $byId, array $byTimestamp): array
    {
        $ids = $this->artistMessageIds($draft);
        $items = [];

        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $items[] = $byId[$id];
            }
        }

        if ($items !== []) {
            return array_slice($items, 0, 3);
        }

        $at = $draft->artist_replied_at?->getTimestamp() ?? 0;

        if ($at === 0) {
            return [];
        }

        foreach ($byTimestamp as $row) {
            if ($row['timestamp'] >= $at - 30 && $row['timestamp'] <= $at + 120) {
                $items[] = $row['item'];
            }
        }

        return array_slice($items, 0, 3);
    }

    /**
     * @return array<int, string>
     */
    private function artistMessageIds(AgentDraft $draft): array
    {
        if ($draft->lead_id === null || $draft->artist_replied_at === null) {
            return [];
        }

        try {
            $rows = DB::select(
                "select message->'additional_kwargs'->>'wa_message_id' as wa_id
                   from agent_memory.chat_memory
                  where session_id = ?
                    and (message->'additional_kwargs'->>'author') = 'artist'
                    and created_at >= ?::timestamptz
                    and created_at <= ?::timestamptz",
                [
                    (string) $draft->lead_id,
                    ($draft->created_at ?? $draft->artist_replied_at)->toIso8601String(),
                    $draft->artist_replied_at->copy()->addMinutes(2)->toIso8601String(),
                ]
            );
        } catch (\Throwable $e) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($row) => trim((string) ($row->wa_id ?? '')),
            $rows,
        )));
    }
}
