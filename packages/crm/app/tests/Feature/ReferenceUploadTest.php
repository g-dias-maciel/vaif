<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Lead;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReferenceUploadTest extends TestCase
{
    use RefreshDatabase;

    private function project(): Project
    {
        $artist = Artist::create([
            'name' => 'Artista',
            'status' => 'live',
            'timezone' => 'America/Sao_Paulo',
            'wa_session_slug' => 'ref-test',
        ]);

        $lead = Lead::create([
            'id' => (string) Str::uuid(),
            'artist_id' => $artist->id,
            'name' => 'Cliente',
            'phone_e164' => '+5511900000000',
        ]);

        return Project::create([
            'artist_id' => $artist->id,
            'lead_id' => $lead->id,
            'stage_key' => 'novo',
        ]);
    }

    public function test_it_downloads_waha_media_and_attaches_it_to_the_project(): void
    {
        Storage::fake('public');
        Http::fake([
            '*/api/files/*' => Http::response('fake-jpeg-bytes', 200, ['Content-Type' => 'image/jpeg']),
        ]);

        config([
            'transport.crm_internal_token' => 'test-token',
            'transport.waha_api_key' => 'waha-key',
        ]);

        $project = $this->project();

        $response = $this->withHeader('X-Crm-Token', 'test-token')->postJson('/api/references', [
            'artist_id' => $project->artist_id,
            'project_id' => $project->id,
            'url' => 'https://waha.vaif.com.br/api/files/default/ABC.jpeg',
            'mime' => 'image/jpeg',
        ]);

        $response->assertOk()->assertJsonPath('ok', true);
        $response->assertJsonPath('image_url', $project->refresh()->reference_pics[0]);

        $project->refresh();
        $this->assertCount(1, $project->reference_pics);
        $this->assertStringContainsString('/storage/references/', $project->reference_pics[0]);
        $this->assertStringEndsWith('.jpg', $project->reference_pics[0]);

        $path = str_replace('/storage/', '', $project->reference_pics[0]);
        Storage::disk('public')->assertExists($path);
    }

    public function test_it_stores_a_non_media_link_without_fetching_it(): void
    {
        Storage::fake('public');
        Http::fake();

        config(['transport.crm_internal_token' => 'test-token']);

        $project = $this->project();

        $response = $this->withHeader('X-Crm-Token', 'test-token')->postJson('/api/references', [
            'artist_id' => $project->artist_id,
            'project_id' => $project->id,
            'url' => 'https://pinterest.com/pin/123',
        ]);

        $response->assertOk();

        $this->assertSame(['https://pinterest.com/pin/123'], $project->refresh()->reference_pics);
        Http::assertNothingSent();
    }

    public function test_it_accepts_a_batch_and_returns_the_first_image(): void
    {
        Storage::fake('public');
        Http::fake();

        config([
            'transport.crm_internal_token' => 'test-token',
            'transport.waha_api_key' => 'waha-key',
        ]);

        $project = $this->project();

        $response = $this->withHeader('X-Crm-Token', 'test-token')->postJson('/api/references', [
            'artist_id' => $project->artist_id,
            'project_id' => $project->id,
            'references' => [
                ['url' => 'https://pinterest.com/pin/123', 'mime' => null],
                ['url' => 'https://example.com/reference.jpg', 'mime' => 'image/jpeg'],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('image_url', 'https://example.com/reference.jpg');

        $this->assertSame([
            'https://pinterest.com/pin/123',
            'https://example.com/reference.jpg',
        ], $project->refresh()->reference_pics);
        Http::assertNothingSent();
    }

    public function test_it_accepts_an_empty_batch_for_text_only_turns(): void
    {
        config(['transport.crm_internal_token' => 'test-token']);

        $project = $this->project();

        $this->withHeader('X-Crm-Token', 'test-token')->postJson('/api/references', [
            'artist_id' => $project->artist_id,
            'project_id' => $project->id,
            'references' => [],
        ])->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('image_url', null)
            ->assertJsonPath('references', []);
    }

    public function test_it_rejects_an_invalid_internal_token(): void
    {
        config(['transport.crm_internal_token' => 'test-token']);

        $project = $this->project();

        $this->postJson('/api/references', [
            'artist_id' => $project->artist_id,
            'project_id' => $project->id,
            'url' => 'https://waha.vaif.com.br/api/files/default/ABC.jpeg',
        ])->assertUnauthorized();
    }
}
