<?php

namespace App\Services\Agent;

use App\Models\AgentDraft;
use Illuminate\Support\Facades\DB;

/**
 * Persistence for copilot drafts and their pairing with the artist's reply.
 */
class DraftStore
{
    public function findByTurn(string $artistId, string $turnId): ?AgentDraft
    {
        return AgentDraft::query()
            ->where('artist_id', $artistId)
            ->where('turn_id', $turnId)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): AgentDraft
    {
        return DB::transaction(function () use ($data) {
            AgentDraft::query()
                ->where('artist_id', $data['artist_id'])
                ->where('chat_id', $data['chat_id'])
                ->where('status', AgentDraft::STATUS_PENDING)
                ->update(['status' => AgentDraft::STATUS_SUPERSEDED]);

            return AgentDraft::create($data);
        });
    }

    public function latestOpenForChat(string $artistId, string $chatId): ?AgentDraft
    {
        try {
            return AgentDraft::query()
                ->where('artist_id', $artistId)
                ->where('chat_id', $chatId)
                ->whereIn('status', [AgentDraft::STATUS_PENDING, AgentDraft::STATUS_REPLIED])
                ->where('created_at', '>=', now()->subDay())
                ->orderByDesc('created_at')
                ->first();
        } catch (\Throwable $e) {
            // Before the calibration migration, the message path must still
            // work; there is simply nothing to pair.
            return null;
        }
    }

    /**
     * Attach one artist WhatsApp message to the draft. Multiple bubbles in the
     * same minute are concatenated so the comparison sees the full answer.
     */
    public function attachArtistReplyAt(AgentDraft $draft, string $text, ?\DateTimeInterface $at = null): AgentDraft
    {
        $text = trim($text);

        if ($text === '' || $draft->status === AgentDraft::STATUS_COMPARED || $draft->artist_reply !== null) {
            return $draft;
        }

        $repliedAt = $at ? \Carbon\CarbonImmutable::instance($at) : now();

        $draft->artist_reply = $text;
        $draft->artist_replied_at = $repliedAt;
        $draft->response_seconds = max(0, (int) $draft->created_at->diffInSeconds($repliedAt));
        $draft->status = AgentDraft::STATUS_REPLIED;
        $draft->save();

        return $draft;
    }

    public function attachArtistReply(AgentDraft $draft, string $text): AgentDraft
    {
        $text = trim($text);

        if ($text === '' || $draft->status === AgentDraft::STATUS_COMPARED) {
            return $draft;
        }

        if ($draft->status === AgentDraft::STATUS_PENDING || $draft->artist_reply === null) {
            $draft->artist_reply = $text;
            $draft->artist_replied_at = now();
            $draft->response_seconds = max(0, (int) $draft->created_at->diffInSeconds(now()));
            $draft->status = AgentDraft::STATUS_REPLIED;
            $draft->save();

            return $draft;
        }

        // A second bubble within five minutes is still part of the same answer.
        if ($draft->artist_replied_at !== null && $draft->artist_replied_at->gt(now()->subMinutes(5))) {
            $draft->artist_reply = trim((string) $draft->artist_reply."\n".$text);
            $draft->artist_replied_at = now();
            $draft->save();
        }

        return $draft;
    }

    /**
     * @return \Illuminate\Support\Collection<int, AgentDraft>
     */
    public function forLead(string $leadId, int $limit = 20)
    {
        return AgentDraft::query()
            ->where('lead_id', $leadId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * True when the lead sent another message after this draft's burst.
     *
     * The artist is probably answering the newer message, so this draft must
     * not consume that reply; the newer draft will pair it when it is created.
     */
    public function hasNewerLeadMessage(AgentDraft $draft): bool
    {
        if ($draft->lead_id === null || $draft->lead_msg_at === null) {
            return false;
        }

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return false;
        }

        try {
            // The buffer knows about a newer inbound even before its draft is
            // generated. That is the earliest and most reliable signal.
            $latest = DB::table('chat_buffers')
                ->where('artist_id', $draft->artist_id)
                ->where('chat_id', $draft->chat_id)
                ->value('last_msg_at');
        } catch (\Throwable $e) {
            return false;
        }

        return $latest !== null
            && \Illuminate\Support\Carbon::parse($latest)->gt($draft->lead_msg_at);
    }

    public function expireStale(int $hours = 24): int
    {
        return AgentDraft::query()
            ->where('status', AgentDraft::STATUS_PENDING)
            ->where('created_at', '<', now()->subHours($hours))
            ->update(['status' => AgentDraft::STATUS_EXPIRED]);
    }
}
