<?php

namespace App\Jobs;

use App\Models\Artist;
use App\Models\ConversationAudit;
use App\Models\Lead;
use App\Services\Agent\ConversationAuditor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Audit one quiet conversation against the VAIF methodology.
 *
 * Idempotent by transcript hash. A failure is logged and retried; it never
 * touches the message path.
 */
class AuditConversation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    public function __construct(
        public readonly string $artistId,
        public readonly string $leadId,
        public readonly bool $force = false,
    ) {}

    public function handle(ConversationAuditor $auditor): void
    {
        $artist = Artist::with('settings')->find($this->artistId);
        $lead = Lead::find($this->leadId);

        if ($artist === null || $lead === null) {
            return;
        }

        $prepared = $auditor->prepare($artist, $lead);

        if ($prepared === null) {
            return;
        }

        $existing = ConversationAudit::query()
            ->where('chat_id', (string) $lead->id)
            ->where('transcript_hash', $prepared['hash'])
            ->first();

        if ($existing !== null && ! $this->force) {
            return;
        }

        try {
            $result = $auditor->review($artist, $lead, $prepared);
        } catch (\Throwable $e) {
            Log::warning('Conversation audit failed', [
                'artist_id' => $this->artistId,
                'lead_id' => $this->leadId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        ConversationAudit::updateOrCreate(
            [
                'chat_id' => (string) $lead->id,
                'transcript_hash' => $result['transcript_hash'],
            ],
            [
                'artist_id' => $artist->id,
                'lead_id' => $lead->id,
                'status' => ConversationAudit::STATUS_READY,
                'score' => $result['score'],
                'summary' => $result['summary'],
                'steps' => $result['steps'],
                'message_count' => $result['message_count'],
                'last_message_at' => $result['last_message_at'],
                'model' => $result['model'],
                'generated_at' => now(),
            ],
        );
    }
}
