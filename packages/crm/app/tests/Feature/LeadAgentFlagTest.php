<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\ArtistSetting;
use App\Models\Lead;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class LeadAgentFlagTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
    }

    private function artist(string $slug = 'default'): Artist
    {
        return Artist::create([
            'name' => 'Artist '.$slug,
            'status' => 'live',
            'timezone' => 'America/Sao_Paulo',
            'wa_session_slug' => $slug,
        ]);
    }

    private function lead(Artist $artist): Lead
    {
        return Lead::create([
            'id' => (string) Str::uuid(),
            'artist_id' => $artist->id,
            'name' => 'Cliente',
            'phone_e164' => '+5511900000000',
        ]);
    }

    private function artistUser(Artist $artist): User
    {
        return User::create([
            'name' => 'Artist User',
            'email' => 'artist-'.$artist->id.'@vaif.test',
            'password' => Hash::make('password'),
            'role' => User::ROLE_ARTIST,
            'artist_id' => $artist->id,
        ]);
    }

    public function test_an_artist_can_silence_and_reactivate_the_agent_for_their_lead(): void
    {
        $artist = $this->artist();
        $lead = $this->lead($artist);
        $user = $this->artistUser($artist);

        $this->actingAs($user)->post("/leads/{$lead->id}/agent")->assertRedirect();

        $lead->refresh();
        $this->assertNotNull($lead->agent_blocked_at);
        $this->assertTrue($lead->agent_blocked);

        $this->actingAs($user)->post("/leads/{$lead->id}/agent")->assertRedirect();

        $this->assertNull($lead->refresh()->agent_blocked_at);
        $this->assertFalse($lead->agent_blocked);
    }

    public function test_silencing_a_lead_also_blocks_the_number_globally(): void
    {
        $artist = $this->artist();
        $lead = $this->lead($artist);
        $user = $this->artistUser($artist);

        // A number already on the list must survive both toggles.
        ArtistSetting::create([
            'artist_id' => $artist->id,
            'blocked_numbers' => ['5599999999999'],
        ]);

        $this->actingAs($user)->post("/leads/{$lead->id}/agent")->assertRedirect();

        $numbers = $artist->settings()->first()->blocked_numbers;
        $this->assertContains('5511900000000', $numbers);
        $this->assertContains('5599999999999', $numbers);

        $this->actingAs($user)->post("/leads/{$lead->id}/agent")->assertRedirect();

        $numbers = $artist->settings()->first()->blocked_numbers;
        $this->assertNotContains('5511900000000', $numbers);
        $this->assertContains('5599999999999', $numbers);
    }

    public function test_the_saved_contact_decision_allows_or_blocks(): void
    {
        $artist = $this->artist();
        $lead = $this->lead($artist);
        $user = $this->artistUser($artist);

        $lead->forceFill(['contact_gate' => 'pending'])->save();

        $this->actingAs($user)
            ->post("/leads/{$lead->id}/contact-gate", ['decision' => 'allow'])
            ->assertRedirect();
        $this->assertSame('allowed', $lead->refresh()->contact_gate);

        $lead->forceFill(['contact_gate' => 'pending'])->save();

        $this->actingAs($user)
            ->post("/leads/{$lead->id}/contact-gate", ['decision' => 'block'])
            ->assertRedirect();

        $lead->refresh();
        $this->assertSame('blocked', $lead->contact_gate);
        $this->assertNotNull($lead->agent_blocked_at);
        $this->assertContains('5511900000000', $artist->settings()->first()->blocked_numbers);
    }

    public function test_the_dashboard_lists_pending_saved_contacts(): void
    {
        $artist = $this->artist();
        $lead = $this->lead($artist);
        $lead->forceFill(['contact_gate' => 'pending', 'name' => 'Vaif Novo'])->save();
        $user = $this->artistUser($artist);

        $this->actingAs($user)->get('/')
            ->assertOk()
            ->assertSee('Contatos salvos aguardando você')
            ->assertSee('Vaif Novo');
    }

    public function test_an_artist_cannot_silence_another_artists_lead(): void
    {
        $owner = $this->artist('owner');
        $other = $this->artist('other');
        $lead = $this->lead($owner);
        $user = $this->artistUser($other);

        $this->actingAs($user)->post("/leads/{$lead->id}/agent")->assertForbidden();

        $this->assertNull($lead->refresh()->agent_blocked_at);
    }

    public function test_the_dashboard_offers_silence_instead_of_mark_lost(): void
    {
        $artist = $this->artist();
        $lead = $this->lead($artist);
        $user = $this->artistUser($artist);

        Project::create([
            'id' => (string) Str::uuid(),
            'artist_id' => $artist->id,
            'lead_id' => $lead->id,
            'stage_key' => 'novo',
        ]);

        $this->actingAs($user)->get('/')
            ->assertOk()
            ->assertSee('btn--icon', false)
            ->assertSee('aria-label="Silenciar agente"', false)
            ->assertDontSee('Marcar perdido');

        $this->actingAs($user)->post("/leads/{$lead->id}/agent")->assertRedirect();

        $this->actingAs($user)->get('/')
            ->assertOk()
            ->assertSee('Agente silenciado')
            ->assertSee('aria-label="Reativar agente"', false)
            ->assertDontSee('Marcar perdido');
    }
}
