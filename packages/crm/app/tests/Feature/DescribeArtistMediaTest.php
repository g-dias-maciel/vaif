<?php

namespace Tests\Feature;

use App\Jobs\CompareDraft;
use App\Jobs\DescribeArtistMedia;
use App\Models\AgentDraft;
use App\Services\Agent\AgentMedia;
use App\Services\Agent\OpenRouterClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class DescribeArtistMediaTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_saves_the_description_and_re_runs_the_comparison(): void
    {
        Queue::fake();

        $draft = AgentDraft::create([
            'artist_id' => (string) Str::uuid(),
            'chat_id' => 'agent:5511@c.us',
            'turn_id' => (string) Str::uuid(),
            'lead_text' => 'Quanto fica?',
            'draft_reply' => 'Fica R$ 600.',
            'status' => AgentDraft::STATUS_COMPARED,
            'artist_reply' => '[FOTO ENVIADA]',
            'artist_replied_at' => now()->subMinute(),
        ]);

        $media = new class(new OpenRouterClient()) extends AgentMedia {
            public function describeArtistMedia(string $url, string $mime = ''): ?string
            {
                return 'Imagem enviada pelo artista: tabela de preços, R$ 600 no braço.';
            }
        };

        (new DescribeArtistMedia((string) $draft->id, [
            ['url' => 'https://waha.test/api/files/x.jpg', 'mime' => 'image/jpeg'],
        ]))->handle($media);

        $draft->refresh();

        $this->assertSame(
            'Imagem enviada pelo artista: tabela de preços, R$ 600 no braço.',
            $draft->artist_media_note,
        );

        Queue::assertPushed(CompareDraft::class, function (CompareDraft $job) use ($draft) {
            return $job->draftId === $draft->id && $job->force === true;
        });
    }

    public function test_it_does_nothing_when_no_media_can_be_described(): void
    {
        Queue::fake();

        $draft = AgentDraft::create([
            'artist_id' => (string) Str::uuid(),
            'chat_id' => 'agent:5511@c.us',
            'turn_id' => (string) Str::uuid(),
            'lead_text' => 'Quanto fica?',
            'draft_reply' => 'Fica R$ 600.',
            'status' => AgentDraft::STATUS_COMPARED,
            'artist_reply' => '[VÍDEO ENVIADO]',
            'artist_replied_at' => now()->subMinute(),
        ]);

        $media = new class(new OpenRouterClient()) extends AgentMedia {
            public function describeArtistMedia(string $url, string $mime = ''): ?string
            {
                return null;
            }
        };

        (new DescribeArtistMedia((string) $draft->id, [
            ['url' => 'https://waha.test/api/files/x.mp4', 'mime' => 'video/mp4'],
        ]))->handle($media);

        $this->assertNull($draft->fresh()->artist_media_note);
        Queue::assertNotPushed(CompareDraft::class);
    }
}
