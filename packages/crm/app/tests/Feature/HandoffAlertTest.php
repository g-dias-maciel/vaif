<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Lead;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class HandoffAlertTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
    }

    private function artist(): Artist
    {
        return Artist::create([
            'name' => 'Artist Handoff',
            'status' => 'live',
            'timezone' => 'America/Sao_Paulo',
            'wa_session_slug' => 'handoff-'.Str::lower(Str::random(8)),
        ]);
    }

    private function user(Artist $artist): User
    {
        return User::create([
            'name' => 'Artist User',
            'email' => 'handoff-'.$artist->id.'@vaif.test',
            'password' => Hash::make('password'),
            'role' => User::ROLE_ARTIST,
            'artist_id' => $artist->id,
        ]);
    }

    private function handoff(Artist $artist, string $phone = '+5511900000222'): Lead
    {
        $lead = Lead::create([
            'id' => (string) Str::uuid(),
            'artist_id' => $artist->id,
            'name' => 'Cliente Handoff',
            'phone_e164' => $phone,
        ]);

        Project::create([
            'id' => (string) Str::uuid(),
            'artist_id' => $artist->id,
            'lead_id' => $lead->id,
            'stage_key' => 'aguardando_artista',
            'handoff_reason' => 'cover_up',
        ]);

        return $lead;
    }

    public function test_the_dashboard_alerts_about_a_pending_handoff(): void
    {
        $artist = $this->artist();
        $user = $this->user($artist);
        $lead = $this->handoff($artist);

        $this->actingAs($user)->get('/')
            ->assertOk()
            ->assertSee('Atendimentos aguardando você')
            ->assertSee($lead->displayName())
            ->assertSee('Cover-up')
            ->assertSee('Ver conversa');
    }

    public function test_the_handoff_list_is_a_collapsible_accordion(): void
    {
        $artist = $this->artist();
        $user = $this->user($artist);
        $this->handoff($artist);

        $response = $this->actingAs($user)->get('/')
            ->assertOk()
            ->assertSee('data-accordion="handoffs"', false)
            ->assertSee('<details', false)
            ->assertSee('<summary>', false);

        // Collapsed by default: no `open` attribute on the handoff accordion.
        $this->assertStringNotContainsString('data-accordion="handoffs" open', $response->getContent());
        // The summary carries the count and a reason breakdown.
        $this->assertStringContainsString('Cover-up 1', $response->getContent());
    }

    public function test_each_handoff_offers_assumir_and_devolver(): void
    {
        $artist = $this->artist();
        $user = $this->user($artist);
        $this->handoff($artist);

        $this->actingAs($user)->get('/')
            ->assertOk()
            ->assertSee('Assumir')
            ->assertSee('Devolver à Beatriz')
            ->assertSee('Aguardando há');
    }

    public function test_a_resolved_handoff_leaves_the_dashboard_list(): void
    {
        $artist = $this->artist();
        $user = $this->user($artist);
        $lead = $this->handoff($artist);

        Project::where('lead_id', $lead->id)->first()
            ->forceFill(['handoff_resolved_at' => now()])
            ->save();

        $this->actingAs($user)->get('/')
            ->assertOk()
            ->assertDontSee('Atendimentos aguardando você');
    }

    public function test_another_artist_cannot_resolve_the_handoff(): void
    {
        $owner = $this->artist();
        $other = $this->artist();
        $lead = $this->handoff($owner);
        $project = Project::where('lead_id', $lead->id)->first();
        $user = $this->user($other);

        $this->assertFalse($user->can('resolveHandoff', $project));

        $this->actingAs($user)
            ->post('/projects/'.$project->id.'/handoff/accept')
            ->assertForbidden();
    }

    public function test_the_artist_can_assume_a_handoff(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires the Postgres RPCs (see packages/crm/db/tests/v2_contract.sql).');
        }

        $artist = $this->artist();
        $user = $this->user($artist);
        $lead = $this->handoff($artist);
        $project = Project::where('lead_id', $lead->id)->first();

        $this->actingAs($user)
            ->post('/projects/'.$project->id.'/handoff/accept')
            ->assertRedirect()
            ->assertSessionHas('status');

        $project->refresh();
        $this->assertSame('qualificando', $project->stage_key);
        $this->assertNotNull($project->handoff_resolved_at);

        $this->assertSame('artist', DB::table('chat_sessions')
            ->where('lead_id', $lead->id)
            ->value('owner'));
    }

    public function test_the_artist_can_hand_a_handoff_back_to_beatriz(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires the Postgres RPCs (see packages/crm/db/tests/v2_contract.sql).');
        }

        $artist = $this->artist();
        $user = $this->user($artist);
        $lead = $this->handoff($artist);
        $project = Project::where('lead_id', $lead->id)->first();

        DB::table('chat_sessions')->insert([
            'artist_id' => $artist->id,
            'chat_id' => 'handoff:'.$lead->phone_e164,
            'lead_id' => $lead->id,
            'owner' => 'artist',
            'agent_state' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)
            ->post('/projects/'.$project->id.'/handoff/return')
            ->assertRedirect()
            ->assertSessionHas('status');

        $project->refresh();
        $this->assertSame('qualificando', $project->stage_key);
        $this->assertNotNull($project->handoff_resolved_at);

        $this->assertSame('agent', DB::table('chat_sessions')
            ->where('lead_id', $lead->id)
            ->value('owner'));
    }

    public function test_the_alert_disappears_after_the_artist_takes_over_the_chat(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires the Postgres RPCs (see packages/crm/db/tests/v2_contract.sql).');
        }

        $artist = $this->artist();
        $user = $this->user($artist);
        $lead = $this->handoff($artist);

        // The artist replies from WhatsApp: n8n calls take_chat_control, which
        // resolves the handoff and moves the project to a live stage.
        DB::select('select * from take_chat_control(?, ?, ?)', [
            $artist->id,
            'handoff:'.$lead->phone_e164,
            $lead->id,
        ]);

        $this->actingAs($user)->get('/')
            ->assertOk()
            ->assertDontSee('Atendimentos aguardando você');

        $project = Project::where('lead_id', $lead->id)->first();
        $this->assertSame('qualificando', $project->stage_key);
        $this->assertNotNull($project->handoff_resolved_at);
    }
}
