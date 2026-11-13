<?php

namespace App\Jobs;

use App\Models\AgentDraft;
use App\Services\Agent\AgentMedia;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Describe the media an artist sent (image or audio) and feed the description
 * back into the calibration comparison.
 *
 * Runs on the `mock` queue: this is retrospective work and must never delay a
 * live WhatsApp turn.
 */
class DescribeArtistMedia implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [15, 45, 90];

    /**
     * @param  array<int, array{url: string, mime: string}>  $media
     */
    public function __construct(
        public readonly string $draftId,
        public readonly array $media,
    ) {
    }

    public function handle(AgentMedia $media): void
    {
        $draft = AgentDraft::find($this->draftId);

        if ($draft === null || $draft->isSimulated()) {
            return;
        }

        $notes = [];
        $ids = [];

        foreach (array_slice($this->media, 0, 3) as $item) {
            $url = trim((string) ($item['url'] ?? ''));

            if ($url === '') {
                continue;
            }

            $note = $media->describeArtistMedia($url, (string) ($item['mime'] ?? ''));

            if ($note !== null && $note !== '') {
                $notes[] = $note;
                $id = trim((string) ($item['id'] ?? ''));

                if ($id !== '') {
                    $ids[] = $id;
                }
            }
        }

        if ($notes === []) {
            return;
        }

        $draft->forceFill([
            'artist_media_note' => implode("\n", $notes),
            'artist_media_ids' => $ids !== [] ? array_values(array_unique($ids)) : null,
        ])->save();

        Log::info('Artist media described', [
            'draft_id' => $draft->id,
            'items' => count($notes),
        ]);

        CompareDraft::dispatch((string) $draft->id, true)
            ->onQueue((string) config('agent.mock_queue', 'mock'));
    }
}
