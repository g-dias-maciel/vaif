<?php

namespace App\Console\Commands;

use App\Jobs\CompareDraft;
use App\Models\AgentDraft;
use App\Models\Artist;
use App\Services\Agent\DraftStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Attach artist replies that are already in the conversation memory to drafts
 * left `pending` by the old pairing bug.
 *
 * Only the newest pending draft per chat is considered, and only when no lead
 * message arrived between the draft and the artist's reply.
 */
class AgentRepairPairings extends Command
{
    protected $signature = 'agent:repair-pairings
        {artist : Artist UUID or WAHA session slug}
        {--dry-run : Count what would be paired without writing}
        {--no-compare : Pair but do not run the judge}';

    protected $description = 'Repair draft↔artist-reply pairings from conversation memory';

    public function handle(DraftStore $drafts): int
    {
        $key = (string) $this->argument('artist');
        $query = Artist::query()->where('wa_session_slug', $key);

        if (Str::isUuid($key)) {
            $query->orWhere('id', $key);
        }

        $artist = $query->first();

        if ($artist === null) {
            $this->error('Artist not found.');

            return self::FAILURE;
        }

        $pending = AgentDraft::query()
            ->where('artist_id', $artist->id)
            ->where('source', '!=', AgentDraft::SOURCE_SIMULATED)
            ->where('status', AgentDraft::STATUS_PENDING)
            ->whereNull('artist_reply')
            ->whereNotNull('lead_id')
            ->whereNotNull('lead_msg_at')
            ->orderByDesc('lead_msg_at')
            ->get()
            ->unique('chat_id');

        $dryRun = (bool) $this->option('dry-run');
        $paired = 0;
        $skipped = 0;
        $unanswered = 0;

        foreach ($pending as $draft) {
            $reply = $this->artistReplyAfter($draft);

            if ($reply === null) {
                $unanswered++;

                continue;
            }

            if ($reply['intervening_lead']) {
                $skipped++;

                continue;
            }

            if (! $dryRun) {
                $drafts->attachArtistReplyAt($draft, $reply['text'], $reply['at']);

                if ($draft->status === AgentDraft::STATUS_REPLIED && ! $this->option('no-compare')) {
                    CompareDraft::dispatch((string) $draft->id)
                        ->delay(now()->addSeconds(10))
                        ->onQueue((string) config('agent.queue', 'agent'));
                }
            }

            $paired++;
        }

        $this->table(['metric', 'value'], [
            ['artist', $artist->name],
            ['candidate chats', $pending->count()],
            ['paired', $paired],
            ['skipped (newer lead message)', $skipped],
            ['no artist reply found', $unanswered],
            ['dry run', $dryRun ? 'yes' : 'no'],
        ]);

        if ($dryRun) {
            $this->warn('Dry run: nothing was written.');
        }

        return self::SUCCESS;
    }

    /**
     * @return array{text: string, at: \DateTimeImmutable, intervening_lead: bool}|null
     */
    private function artistReplyAfter(AgentDraft $draft): ?array
    {
        $after = $draft->lead_msg_at?->toIso8601String();
        if ($after === null) {
            return null;
        }

        try {
            $first = DB::selectOne(
                "select m.created_at, m.message->>'content' as content
                   from agent_memory.chat_memory m
                  where m.session_id = ?
                    and (m.message->'additional_kwargs'->>'author') = 'artist'
                    and m.created_at > ?::timestamptz
                    and m.created_at < ?::timestamptz + interval '24 hours'
                  order by m.created_at
                  limit 1",
                [(string) $draft->lead_id, $after, $after]
            );
        } catch (\Throwable $e) {
            return null;
        }

        if ($first === null) {
            return null;
        }

        $firstAt = new \DateTimeImmutable((string) $first->created_at);

        try {
            $intervening = DB::selectOne(
                "select 1
                   from agent_memory.chat_memory h
                  where h.session_id = ?
                    and h.message->>'type' = 'human'
                    and h.created_at > ?::timestamptz
                    and h.created_at < ?::timestamptz
                  limit 1",
                [(string) $draft->lead_id, $after, $firstAt->format('c')]
            );

            $bubbles = DB::select(
                "select m.created_at, m.message->>'content' as content
                   from agent_memory.chat_memory m
                  where m.session_id = ?
                    and (m.message->'additional_kwargs'->>'author') = 'artist'
                    and m.created_at >= ?::timestamptz
                    and m.created_at <= ?::timestamptz + interval '5 minutes'
                  order by m.created_at",
                [(string) $draft->lead_id, $firstAt->format('c'), $firstAt->format('c')]
            );
        } catch (\Throwable $e) {
            return null;
        }

        $texts = [];
        foreach ($bubbles as $bubble) {
            $content = trim((string) ($bubble->content ?? ''));
            if ($content !== '') {
                $texts[] = $content;
            }
        }

        if ($texts === []) {
            return null;
        }

        return [
            'text' => implode("\n", $texts),
            'at' => $firstAt,
            'intervening_lead' => $intervening !== null,
        ];
    }
}
