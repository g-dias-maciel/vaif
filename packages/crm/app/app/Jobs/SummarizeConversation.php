<?php

namespace App\Jobs;

use App\Models\Artist;
use App\Models\Lead;
use App\Services\Agent\ConversationSummarizer;
use App\Services\Agent\ObservedStore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Refresh the Lead's conversation summary after a quiet window.
 *
 * Several observed turns can arrive in one burst; each dispatches a job with
 * its own message time. Only the job that still sees the latest message does
 * the work, so a busy chat costs one summary per quiet window.
 */
class SummarizeConversation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public function __construct(
        public readonly string $artistId,
        public readonly string $leadId,
        public readonly string $observedAt,
    ) {}

    public function handle(ObservedStore $observed, ConversationSummarizer $summarizer): void
    {
        $artist = Artist::find($this->artistId);
        $lead = Lead::find($this->leadId);

        if ($artist === null || $lead === null) {
            return;
        }

        $latest = $observed->latestMessageAt($this->leadId);

        if ($latest !== null && $this->isNewerThanObserved($latest)) {
            return;
        }

        try {
            $summary = $summarizer->summarize($artist, $lead);
        } catch (\Throwable $e) {
            Log::warning('Conversation summary failed', [
                'artist_id' => $this->artistId,
                'lead_id' => $this->leadId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        if ($summary === null) {
            return;
        }

        $observed->upsertSummary(
            $this->leadId,
            $summary['body'],
            $summary['message_count'],
            $summary['model'],
        );
    }

    private function isNewerThanObserved(string $latest): bool
    {
        try {
            return Carbon::parse($latest)->gt(Carbon::parse($this->observedAt)->addSecond());
        } catch (\Throwable) {
            return false;
        }
    }
}
