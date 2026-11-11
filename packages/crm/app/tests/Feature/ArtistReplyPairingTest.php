<?php

namespace Tests\Feature;

use App\Jobs\CompareDraft;
use App\Jobs\PairArtistReply;
use App\Models\AgentDraft;
use App\Services\Agent\DraftStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class ArtistReplyPairingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_deferred_artist_reply_is_attached_to_the_newest_draft(): void
    {
        Queue::fake();

        $artistId = (string) Str::uuid();

        $draft = AgentDraft::create([
            'artist_id' => $artistId,
            'chat_id' => 'agent:5511@c.us',
            'turn_id' => (string) Str::uuid(),
            'lead_text' => 'Quanto fica?',
            'lead_msg_at' => now()->subMinutes(2),
            'intent' => 'ask_price',
            'draft_reply' => 'Fica R$ 600,00.',
            'status' => AgentDraft::STATUS_PENDING,
            'created_at' => now()->subMinutes(2),
        ]);

        (new PairArtistReply(
            $artistId,
            'agent:5511@c.us',
            'Fica R$ 600, qual o tamanho?',
            now()->toIso8601String(),
        ))->handle(new DraftStore());

        $draft->refresh();

        $this->assertSame(AgentDraft::STATUS_REPLIED, $draft->status);
        $this->assertSame('Fica R$ 600, qual o tamanho?', $draft->artist_reply);
        Queue::assertPushed(CompareDraft::class);
    }

    public function test_it_does_not_overwrite_an_existing_artist_reply(): void
    {
        Queue::fake();

        $artistId = (string) Str::uuid();

        $draft = AgentDraft::create([
            'artist_id' => $artistId,
            'chat_id' => 'agent:5511@c.us',
            'turn_id' => (string) Str::uuid(),
            'lead_text' => 'Quanto fica?',
            'lead_msg_at' => now()->subMinutes(2),
            'intent' => 'ask_price',
            'draft_reply' => 'Fica R$ 600,00.',
            'status' => AgentDraft::STATUS_REPLIED,
            'artist_reply' => 'Resposta original',
            'artist_replied_at' => now()->subMinute(),
            'created_at' => now()->subMinutes(2),
        ]);

        (new PairArtistReply(
            $artistId,
            'agent:5511@c.us',
            'Segunda bolha',
            now()->toIso8601String(),
        ))->handle(new DraftStore());

        $draft->refresh();

        $this->assertSame('Resposta original', $draft->artist_reply);
    }
}
