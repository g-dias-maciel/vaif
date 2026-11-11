<?php

namespace Tests\Feature;

use App\Models\AgentDraft;
use App\Models\Artist;
use App\Models\ArtistSetting;
use App\Models\Lead;
use App\Jobs\GenerateMockDrafts;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class CalibrationPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);

        config([
            'transport.waha_url' => 'https://waha.test',
            'transport.waha_api_key' => 'key',
            'transport.waha_webhook_url' => 'https://crm.test/api/webhooks/waha',
            'transport.waha_webhook_token' => 'crm-token',
            'transport.n8n_webhook_url' => 'https://n8n.test/webhook/waha-webhook',
            'transport.n8n_webhook_token' => 'n8n-token',
            'agent.calibration.min_compared' => 2,
        ]);
    }

    private function staff(): User
    {
        return User::create([
            'name' => 'Staff',
            'email' => 'staff-'.Str::random(6).'@vaif.test',
            'password' => Hash::make('password'),
            'role' => User::ROLE_STAFF,
        ]);
    }

    private function artist(string $mode = 'draft'): Artist
    {
        $artist = Artist::create([
            'name' => 'Calibration Artist',
            'status' => 'live',
            'timezone' => 'America/Sao_Paulo',
            'wa_session_slug' => 'calibration-artist',
        ]);

        ArtistSetting::create([
            'artist_id' => $artist->id,
            'display_name' => 'Calibration Artist',
            'reply_mode' => $mode,
        ]);

        return $artist;
    }

    private function fakeWaha(string $webhookUrl = 'https://n8n.test/webhook/waha-webhook'): void
    {
        Http::fake([
            'https://waha.test/api/sessions/calibration-artist' => Http::sequence()
                ->push([
                    'name' => 'calibration-artist',
                    'status' => 'WORKING',
                    'me' => ['pushName' => 'Eva'],
                    'config' => ['webhooks' => [[
                        'url' => $webhookUrl,
                        'events' => ['message', 'presence.update'],
                        'customHeaders' => [['name' => 'X-VAIF-Webhook-Token', 'value' => 'secret']],
                    ]]],
                ])
                ->push(['name' => 'calibration-artist', 'status' => 'WORKING'])
                ->push(['name' => 'calibration-artist', 'status' => 'WORKING']),
        ]);
    }

    public function test_staff_can_see_the_calibration_overview(): void
    {
        $artist = $this->artist();

        $this->actingAs($this->staff())
            ->get(route('calibration.index'))
            ->assertOk()
            ->assertSee('Calibração do agente')
            ->assertSee($artist->displayName());
    }

    public function test_artists_cannot_see_the_calibration_panel(): void
    {
        $artist = $this->artist();
        $user = User::create([
            'name' => 'Artist Login',
            'email' => 'artist-'.Str::random(6).'@vaif.test',
            'password' => Hash::make('password'),
            'role' => User::ROLE_ARTIST,
            'artist_id' => $artist->id,
        ]);

        $this->actingAs($user)->get(route('calibration.index'))->assertForbidden();
    }

    public function test_staff_can_change_the_reply_mode(): void
    {
        $artist = $this->artist('draft');

        $this->actingAs($this->staff())
            ->post(route('calibration.mode', $artist), ['reply_mode' => 'off'])
            ->assertRedirect();

        $this->assertSame('off', $artist->fresh()->settings->reply_mode);
    }

    public function test_auto_is_blocked_until_the_gates_pass(): void
    {
        $artist = $this->artist('draft');

        $this->actingAs($this->staff())
            ->post(route('calibration.mode', $artist), ['reply_mode' => 'auto'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame('draft', $artist->fresh()->settings->reply_mode);
    }

    public function test_staff_can_force_auto(): void
    {
        $artist = $this->artist('draft');

        $this->actingAs($this->staff())
            ->post(route('calibration.mode', $artist), ['reply_mode' => 'auto', 'force' => '1'])
            ->assertRedirect();

        $this->assertSame('auto', $artist->fresh()->settings->reply_mode);
    }

    public function test_show_renders_the_draft_and_comparison(): void
    {
        $artist = $this->artist();
        $this->fakeWaha();
        $lead = Lead::create([
            'id' => (string) Str::uuid(),
            'artist_id' => $artist->id,
            'name' => 'Lead',
            'phone_e164' => '+5511900000000',
        ]);

        AgentDraft::create([
            'artist_id' => $artist->id,
            'lead_id' => $lead->id,
            'chat_id' => 'calibration-artist:5511@c.us',
            'turn_id' => (string) Str::uuid(),
            'lead_text' => 'Quanto fica?',
            'draft_reply' => 'Fica R$ 600,00 à vista.',
            'status' => AgentDraft::STATUS_COMPARED,
            'artist_reply' => 'Fica R$ 600,00, quer ver os horários?',
            'comparison' => [
                'deterministic' => ['ok' => true, 'violations' => []],
                'judge' => [
                    'acceptable' => true,
                    'covered_lead_question' => true,
                    'missing_handoff' => false,
                    'tone' => 4,
                    'verdict' => 'draft_better',
                    'rationale' => 'Mesmo preço e próximo passo correto.',
                ],
            ],
        ]);

        $this->actingAs($this->staff())
            ->get(route('calibration.show', $artist))
            ->assertOk()
            ->assertSee('Fica R$ 600,00 à vista.')
            ->assertSee('Fica R$ 600,00, quer ver os horários?')
            ->assertSee('draft_better')
            ->assertSee('Apontar para o CRM');
    }

    public function test_staff_can_request_mock_drafts_for_a_lead(): void
    {
        Queue::fake();
        $artist = $this->artist();
        $lead = Lead::create([
            'id' => (string) Str::uuid(),
            'artist_id' => $artist->id,
            'name' => 'Lead',
            'phone_e164' => '+5511900000000',
        ]);

        $this->actingAs($this->staff())
            ->post(route('calibration.mock', [$artist, $lead]))
            ->assertRedirect()
            ->assertSessionHas('status');

        Queue::assertPushed(GenerateMockDrafts::class, function (GenerateMockDrafts $job) use ($artist, $lead) {
            return $job->artistId === $artist->id && $job->leadId === $lead->id;
        });
    }

    public function test_staff_can_describe_artist_media(): void
    {
        Queue::fake();
        $artist = $this->artist();
        $this->fakeWaha();

        $lead = Lead::create([
            'id' => (string) Str::uuid(),
            'artist_id' => $artist->id,
            'name' => 'Lead',
            'phone_e164' => '+5511900000000',
        ]);

        $draft = AgentDraft::create([
            'artist_id' => $artist->id,
            'lead_id' => $lead->id,
            'chat_id' => 'calibration-artist:5511900000000@c.us',
            'turn_id' => (string) Str::uuid(),
            'lead_text' => 'Quanto fica no quadril?',
            'draft_reply' => 'Fica R$ 600.',
            'status' => AgentDraft::STATUS_COMPARED,
            'artist_reply' => '[MÍDIA ENVIADA]',
            'artist_replied_at' => now()->subMinute(),
        ]);

        $this->actingAs($this->staff())
            ->post(route('calibration.media-note', [$artist, $draft]), [
                'note' => 'Mandou a tabela de preços, R$ 250 no quadril.',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame(
            'Mandou a tabela de preços, R$ 250 no quadril.',
            $draft->fresh()->artist_media_note,
        );

        Queue::assertPushed(\App\Jobs\CompareDraft::class, function ($job) use ($draft) {
            return $job->draftId === $draft->id && $job->force === true;
        });
    }

    public function test_staff_can_point_the_session_at_the_crm(): void
    {
        $artist = $this->artist();
        $this->fakeWaha();

        $this->actingAs($this->staff())
            ->post(route('calibration.webhook', $artist), ['target' => 'crm'])
            ->assertRedirect()
            ->assertSessionHas('status');

        Http::assertSent(function ($request) {
            if ($request->method() !== 'PUT') {
                return false;
            }

            return $request['config']['webhooks'][0]['url'] === 'https://crm.test/api/webhooks/waha'
                && $request['config']['webhooks'][0]['customHeaders'][0]['value'] === 'crm-token'
                && $request['config']['webhooks'][0]['events'] === ['message.any', 'presence.update'];
        });
    }
}
