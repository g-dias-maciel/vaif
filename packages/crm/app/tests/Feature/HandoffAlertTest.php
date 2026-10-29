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
            'wa_session_slug' => 'handoff',
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

    public function test_the_alert_disappears_after_the_artist_takes_over_the_chat(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('chat_sessions is Postgres-only.');
        }

        $artist = $this->artist();
        $user = $this->user($artist);
        $lead = $this->handoff($artist);

        DB::table('chat_sessions')->insert([
            'artist_id' => $artist->id,
            'chat_id' => 'handoff:'.$lead->phone_e164,
            'lead_id' => $lead->id,
            'owner' => 'artist',
            'agent_state' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)->get('/')
            ->assertOk()
            ->assertDontSee('Atendimentos aguardando você');
    }
}
