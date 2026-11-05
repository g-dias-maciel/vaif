<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\CalendarEntry;
use App\Models\Lead;
use App\Models\Project;
use App\Models\User;
use App\Services\Google\GoogleCalendarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

class SettingsGoogleSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        Http::fake();

        config(['transport.waha_url' => 'https://waha.test']);
    }

    private function artist(): Artist
    {
        return Artist::create([
            'name' => 'Artist Google',
            'status' => 'live',
            'timezone' => 'America/Sao_Paulo',
            'wa_session_slug' => 'google-settings',
            'google_calendar_id' => 'artist@example.com',
            'google_calendar_summary' => 'Agenda da Tattoo',
            'google_calendar_timezone' => 'America/Sao_Paulo',
            'google_connected_at' => now(),
        ]);
    }

    private function user(Artist $artist): User
    {
        return User::create([
            'name' => 'Artist User',
            'email' => 'artist-'.$artist->id.'@vaif.test',
            'password' => Hash::make('password'),
            'role' => User::ROLE_ARTIST,
            'artist_id' => $artist->id,
        ]);
    }

    private function booking(Artist $artist, string $error): CalendarEntry
    {
        $lead = Lead::create([
            'id' => (string) Str::uuid(),
            'artist_id' => $artist->id,
            'name' => 'João',
            'phone_e164' => '+5521999553136',
        ]);

        $project = Project::create([
            'id' => (string) Str::uuid(),
            'artist_id' => $artist->id,
            'lead_id' => $lead->id,
            'stage_key' => 'agendado',
        ]);

        return CalendarEntry::create([
            'id' => (string) Str::uuid(),
            'artist_id' => $artist->id,
            'kind' => 'booking',
            'lead_id' => $lead->id,
            'project_id' => $project->id,
            'start_at' => now()->addDays(2),
            'end_at' => now()->addDays(2)->addHours(2),
            'active' => true,
            'google_sync_pending' => true,
            'google_sync_error' => $error,
        ]);
    }

    public function test_the_settings_surface_the_sync_error(): void
    {
        $artist = $this->artist();
        $this->booking($artist, 'You need to have writer access to this calendar.');
        $user = $this->user($artist);

        $this->actingAs($user)->get('/config')
            ->assertOk()
            ->assertSee('Falha ao sincronizar com o Google')
            ->assertSee('writer access');
    }

    public function test_a_read_only_calendar_sets_the_sync_error(): void
    {
        $artist = $this->artist();
        $this->booking($artist, '');
        $user = $this->user($artist);

        $this->mock(GoogleCalendarService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getCalendar')->andReturn([
                'id' => 'artist@example.com',
                'summary' => 'Agenda da Tattoo',
                'timezone' => 'America/Sao_Paulo',
            ]);
            $mock->shouldReceive('canWrite')->andReturn(false);
        });

        $this->actingAs($user)->post('/config/google/recheck')->assertRedirect();

        $this->assertDatabaseHas('calendar_entries', [
            'artist_id' => $artist->id,
            'google_sync_error' => 'Sem permissão de escrita na agenda. Compartilhe com “Fazer alterações nos eventos”.',
        ]);
    }
}
