<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\ArtistSetting;
use App\Models\Lead;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProjectStageActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
    }

    private function artist(string $slug, bool $fullSelling): Artist
    {
        $artist = Artist::create([
            'name' => 'Artist '.$slug,
            'status' => 'live',
            'timezone' => 'America/Sao_Paulo',
            'wa_session_slug' => $slug,
        ]);

        ArtistSetting::create([
            'artist_id' => $artist->id,
            'display_name' => 'Artist '.$slug,
            'full_selling' => $fullSelling,
        ]);

        return $artist;
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

    private function project(Artist $artist, Lead $lead, string $stage = 'aguardando_artista'): Project
    {
        return Project::create([
            'id' => (string) Str::uuid(),
            'artist_id' => $artist->id,
            'lead_id' => $lead->id,
            'stage_key' => $stage,
            'placement' => 'braco',
            'body_zone' => 'medio',
        ]);
    }

    private function user(Artist $artist): User
    {
        return User::create([
            'name' => 'Artist User',
            'email' => 'stage-'.$artist->id.'@vaif.test',
            'password' => Hash::make('password'),
            'role' => User::ROLE_ARTIST,
            'artist_id' => $artist->id,
        ]);
    }

    public function test_triage_only_artists_see_the_manual_stage_actions(): void
    {
        $artist = $this->artist('triage', false);
        $lead = $this->lead($artist);
        $this->project($artist, $lead);
        $user = $this->user($artist);

        $this->actingAs($user)->get('/leads/'.$lead->id)
            ->assertOk()
            ->assertSee('Marcar como agendado')
            ->assertSee('Marcar como finalizado');
    }

    public function test_full_selling_artists_do_not_see_the_manual_stage_actions(): void
    {
        $artist = $this->artist('full', true);
        $lead = $this->lead($artist);
        $this->project($artist, $lead);
        $user = $this->user($artist);

        $this->actingAs($user)->get('/leads/'.$lead->id)
            ->assertOk()
            ->assertDontSee('Marcar como agendado')
            ->assertDontSee('Marcar como finalizado');
    }

    public function test_the_dashboard_board_offers_manual_actions_for_triage_only_artists(): void
    {
        $artist = $this->artist('triage', false);
        $lead = $this->lead($artist);
        $this->project($artist, $lead);
        $user = $this->user($artist);

        $this->actingAs($user)->get('/')
            ->assertOk()
            ->assertSee('Agendar')
            ->assertSee('Finalizar');
    }

    public function test_a_full_selling_artist_cannot_schedule_manually(): void
    {
        $artist = $this->artist('full', true);
        $lead = $this->lead($artist);
        $project = $this->project($artist, $lead);
        $user = $this->user($artist);

        $this->actingAs($user)
            ->post('/projects/'.$project->id.'/schedule')
            ->assertForbidden();

        $this->assertSame('aguardando_artista', $project->refresh()->stage_key);
    }

    public function test_an_artist_cannot_schedule_another_artists_project(): void
    {
        $owner = $this->artist('owner', false);
        $other = $this->artist('other', false);
        $lead = $this->lead($owner);
        $project = $this->project($owner, $lead);
        $user = $this->user($other);

        $this->actingAs($user)
            ->post('/projects/'.$project->id.'/schedule')
            ->assertForbidden();

        $this->assertSame('aguardando_artista', $project->refresh()->stage_key);
    }

    public function test_an_artist_can_mark_a_triage_project_as_scheduled(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires the Postgres RPCs (see packages/crm/db/tests/v2_contract.sql).');
        }

        $artist = $this->artist('triage', false);
        $lead = $this->lead($artist);
        $project = $this->project($artist, $lead);
        $user = $this->user($artist);

        $this->actingAs($user)
            ->post('/projects/'.$project->id.'/schedule')
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame('agendado', $project->refresh()->stage_key);
    }

    public function test_an_artist_can_finalize_a_triage_project_without_a_booking(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires the Postgres RPCs (see packages/crm/db/tests/v2_contract.sql).');
        }

        $artist = $this->artist('triage', false);
        $lead = $this->lead($artist);
        $project = $this->project($artist, $lead);
        $user = $this->user($artist);

        $this->actingAs($user)
            ->post('/projects/'.$project->id.'/complete')
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame('fechado', $project->refresh()->stage_key);
        $this->assertTrue($lead->refresh()->is_returning);
    }
}
