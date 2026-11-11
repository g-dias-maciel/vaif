<?php

namespace App\Jobs;

use App\Models\AgentDraft;
use App\Services\Agent\DraftStore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Pair an artist's WhatsApp reply with the copilot draft it answers.
 *
 * The reply can arrive while the lead is still typing a newer burst. In that
 * case the draft that should own the reply does not exist yet, so the pairing
 * is retried instead of being dropped (which left drafts stuck as `pending`).
 */
class PairArtistReply implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 8;

    /** @var array<int, int> */
    public array $backoff = [10, 15, 20, 30, 45];

    public function __construct(
        public readonly string $artistId,
        public readonly string $chatId,
        public readonly string $text,
        public readonly ?string $at = null,
    ) {
    }

    public function handle(DraftStore $drafts): void
    {
        $draft = $drafts->latestOpenForChat($this->artistId, $this->chatId)
            ?? $drafts->latestUnansweredForChat($this->artistId, $this->chatId);

        if ($draft === null) {
            return;
        }

        // The lead sent another burst and its draft has not been created yet.
        // Give the debounce job a few seconds; after the last attempt attach to
        // the newest draft available so the reply is never lost.
        $attempts = $this->job !== null ? $this->attempts() : 1;

        if ($drafts->hasNewerLeadMessage($draft) && $attempts < 6) {
            if ($this->job !== null) {
                $this->release(10);
            }

            return;
        }

        $at = $this->at !== null ? new \DateTimeImmutable($this->at) : null;
        $draft = $drafts->attachArtistReplyAt($draft, $this->text, $at);

        if ($draft->status !== AgentDraft::STATUS_REPLIED) {
            return;
        }

        CompareDraft::dispatch((string) $draft->id)
            ->delay(now()->addMinute())
            ->onQueue((string) config('agent.queue', 'agent'));
    }
}
