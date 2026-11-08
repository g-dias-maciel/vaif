<?php

namespace App\Jobs;

use App\Models\AgentDraft;
use App\Services\Agent\DraftComparator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Compare a copilot draft with the artist's real reply.
 *
 * Dispatched by the webhook when a `fromMe` message is paired, and by the
 * draft processor when the artist replied before the draft finished. The
 * delay + release window lets multiple artist bubbles land in one answer.
 */
class CompareDraft implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public readonly string $draftId)
    {
    }

    public function handle(DraftComparator $comparator): void
    {
        $draft = AgentDraft::find($this->draftId);

        if ($draft === null || $draft->comparison !== null || trim((string) $draft->artist_reply) === '') {
            return;
        }

        if ($draft->status !== AgentDraft::STATUS_REPLIED) {
            return;
        }

        // Wait until the artist has been quiet for a moment, so a second
        // bubble is part of the same answer.
        if ($draft->artist_replied_at !== null && $draft->artist_replied_at->gt(now()->subSeconds(45))) {
            if ($this->job !== null) {
                $this->release(20);
            }

            return;
        }

        try {
            $comparator->compare($draft);
        } catch (\Throwable $e) {
            Log::error('Draft comparison failed', [
                'draft_id' => $draft->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
