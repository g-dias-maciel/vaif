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

class DashboardGroupingTest extends TestCase
{
    use RefreshDatabase;

    private function artist(): Artist
    {
        $artist = Artist::create([
            'name' => 'Artist Grouping',
            'status' => 'live',
            'timezone' => 'America/Sao_Paulo',
            'wa_session_slug' => 'grouping',
        ]);

        ArtistSetting::create([
            'artist_id' => $artist->id,
            'display_name' => 'Artist Grouping',
            'full_selling' => true,
        ]);

        return $artist;
    }

    private function user(Artist $artist): User
    {
        return User::create([
            'name' => 'Artist User',
            'email' => 'grouping-'.$artist->id.'@vaif.test',
            'password' => Hash::make('password'),
            'role' => User::ROLE_ARTIST,
            'artist_id' => $artist->id,
        ]);
    }

    private function project(Artist $artist, string $stage, string $name = 'Cliente'): Project
    {
        static $phone = 0;
        $phone++;

        $lead = Lead::create([
            'id' => (string) Str::uuid(),
            'artist_id' => $artist->id,
            'name' => $name,
            'phone_e164' => '+55119000'.str_pad((string) $phone, 5, '0', STR_PAD_LEFT),
        ]);

        return Project::create([
            'id' => (string) Str::uuid(),
            'artist_id' => $artist->id,
            'lead_id' => $lead->id,
            'stage_key' => $stage,
        ]);
    }

    public function test_the_dashboard_shows_three_pipeline_lanes(): void
    {
        $artist = $this->artist();
        $user = $this->user($artist);

        $this->project($artist, 'novo');
        $this->project($artist, 'fechado');
        $this->project($artist, 'bloqueado');

        $this->actingAs($user)->get('/')
            ->assertOk()
            ->assertSee('Em atendimento')
            ->assertSee('Encerrados')
            ->assertSee('Bloqueados')
            ->assertSee('precisam de você')
            ->assertSee('em conversa')
            ->assertSee('agendados');
    }

    public function test_a_busy_column_is_capped_with_a_ver_todos_expander(): void
    {
        $artist = $this->artist();
        $user = $this->user($artist);

        for ($i = 0; $i < 8; $i++) {
            $this->project($artist, 'novo', 'Cliente '.$i);
        }

        $this->actingAs($user)->get('/')
            ->assertOk()
            ->assertSee('Ver todos (8)')
            ->assertSee('data-persist="stage-novo"', false);
    }

    public function test_the_board_has_a_search_box(): void
    {
        $artist = $this->artist();
        $user = $this->user($artist);
        $this->project($artist, 'qualificando');

        $this->actingAs($user)->get('/')
            ->assertOk()
            ->assertSee('id="board-search"', false)
            ->assertSee('Buscar por nome ou telefone');
    }

    public function test_an_idle_card_is_tagged_as_stopped(): void
    {
        $artist = $this->artist();
        $user = $this->user($artist);
        $project = $this->project($artist, 'qualificando');

        Project::whereKey($project->id)->update([
            'updated_at' => now()->subDays(5),
            'created_at' => now()->subDays(5),
        ]);

        $this->actingAs($user)->get('/')
            ->assertOk()
            ->assertSee('Parado há');
    }
}
