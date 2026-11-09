<?php

namespace App\Jobs;

use App\Models\Artist;
use App\Models\Lead;
use App\Services\Agent\MockDraftGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateMockDrafts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public readonly string $artistId,
        public readonly string $leadId,
        public readonly int $limit = 20,
    ) {
        $this->onQueue((string) config('agent.mock_queue', 'mock'));
    }

    public function handle(MockDraftGenerator $generator): void
    {
        $artist = Artist::find($this->artistId);
        $lead = Lead::find($this->leadId);

        if (! $artist || ! $lead || $lead->artist_id !== $artist->id) {
            return;
        }

        $result = $generator->generateForLead($artist, $lead, $this->limit);

        Log::info('Mock drafts generated', [
            'artist_id' => $artist->id,
            'lead_id' => $lead->id,
            'generated' => $result['generated'],
            'skipped' => $result['skipped'],
            'missing' => $result['missing'],
        ]);
    }
}
