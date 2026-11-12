<?php

namespace App\Jobs;

use App\Models\Artist;
use App\Services\Agent\ObservedTurnProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Observe an artist outbound WhatsApp burst.
 *
 * The webhook already stored the message in the transcript; this job records
 * the interpreted turn and refreshes the conversation summary. It never sends.
 */
class ObserveArtistMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 60];

    public function __construct(
        public readonly string $artistId,
        public readonly string $chatId,
        public readonly string $phone,
        public readonly string $text,
        public readonly ?string $waMessageId = null,
        public readonly ?string $at = null,
    ) {}

    public function handle(ObservedTurnProcessor $processor): void
    {
        $artist = Artist::with('settings')->find($this->artistId);

        if ($artist === null) {
            return;
        }

        // The mode may have changed while the job sat in the queue.
        if (($artist->settings?->reply_mode ?? 'draft') !== 'observe') {
            return;
        }

        if ($artist->status === 'suspended') {
            return;
        }

        $processor->observeArtist(
            $artist,
            $this->chatId,
            $this->phone,
            $this->text,
            $this->waMessageId,
            $this->at,
        );
    }
}
