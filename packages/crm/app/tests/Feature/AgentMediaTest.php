<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Lead;
use App\Models\Project;
use App\Services\Agent\AgentMedia;
use App\Services\Agent\OpenRouterClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AgentMediaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        config([
            'transport.waha_url' => 'https://waha.test',
            'transport.waha_api_key' => 'waha-key',
            'agent.openrouter.key' => 'openrouter-key',
            'agent.openrouter.url' => 'https://openrouter.test/api/v1',
            'agent.models.audio' => 'google/gemini-test',
            'agent.models.vision' => 'google/gemini-test',
        ]);
    }

    private function project(): Project
    {
        $artist = Artist::create([
            'name' => 'Media Artist',
            'status' => 'live',
            'timezone' => 'America/Sao_Paulo',
            'wa_session_slug' => 'media-artist',
        ]);

        $lead = Lead::create([
            'id' => (string) Str::uuid(),
            'artist_id' => $artist->id,
            'phone_e164' => '+5511900000000',
        ]);

        return Project::create([
            'id' => (string) Str::uuid(),
            'artist_id' => $artist->id,
            'lead_id' => $lead->id,
            'stage_key' => 'novo',
        ]);
    }

    public function test_text_without_media_is_untouched(): void
    {
        $project = $this->project();

        $result = (new AgentMedia(new OpenRouterClient()))->prepare(
            $project->artist,
            $project->id,
            'Oi, quero uma tattoo',
            'new',
        );

        $this->assertSame(['text' => 'Oi, quero uma tattoo', 'analysis' => null], $result);
    }

    public function test_a_reference_photo_is_stored_and_analyzed(): void
    {
        $project = $this->project();

        Http::fake([
            'https://waha.test/api/files/*' => Http::response('jpeg-bytes', 200, ['Content-Type' => 'image/jpeg']),
            'https://openrouter.test/*' => Http::response([
                'choices' => [[
                    'message' => ['content' => '{"referencia_de_tatuagem":true,"assunto":"leão com bússola","estilo":"realismo","confianca":"alta"}'],
                ]],
            ], 200),
        ]);

        $result = (new AgentMedia(new OpenRouterClient()))->prepare(
            $project->artist,
            $project->id,
            "[FOTO RECEBIDA]\n[[REF_FOTO:https://waha.test/api/files/1.jpg]]",
            'new',
        );

        $this->assertSame('[FOTO RECEBIDA]', $result['text']);
        $this->assertSame('leão com bússola', $result['analysis']['assunto']);

        $project->refresh();
        $this->assertNotEmpty($project->reference_pics);
        $this->assertSame('leão com bússola', $project->reference_analysis['assunto']);
    }

    public function test_an_audio_is_transcribed_into_the_lead_text(): void
    {
        $project = $this->project();

        Http::fake([
            'https://waha.test/api/files/*' => Http::response('ogg-bytes', 200, ['Content-Type' => 'audio/ogg']),
            'https://openrouter.test/*' => Http::response([
                'choices' => [[
                    'message' => ['content' => 'oi, quero fazer uma tattoo no braço'],
                ]],
            ], 200),
        ]);

        $result = (new AgentMedia(new OpenRouterClient()))->prepare(
            $project->artist,
            $project->id,
            "[ÁUDIO RECEBIDO]\n[[REF_AUDIO:https://waha.test/api/files/a.ogg]]",
            'new',
        );

        $this->assertSame('oi, quero fazer uma tattoo no braço', $result['text']);
    }

    public function test_a_failed_transcription_asks_for_text(): void
    {
        $project = $this->project();

        Http::fake([
            'https://waha.test/api/files/*' => Http::response('', 404),
        ]);

        $result = (new AgentMedia(new OpenRouterClient()))->prepare(
            $project->artist,
            $project->id,
            "[ÁUDIO RECEBIDO]\n[[REF_AUDIO:https://waha.test/api/files/a.ogg]]",
            'new',
        );

        $this->assertStringContainsString('Peça para repetir por texto', $result['text']);
    }
}
