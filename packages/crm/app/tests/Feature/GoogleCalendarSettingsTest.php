<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\ArtistSetting;
use App\Models\CalendarEntry;
use App\Models\Lead;
use App\Models\User;
use App\Services\Google\GoogleCalendarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

class GoogleCalendarSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
    }

    private function artist(): Artist
    {
        $artist = Artist::create([
            'name' => 'Artist Google',
            'status' => 'live',
            'timezone' => 'America/Sao_Paulo',
            'wa_session_slug' => 'google',
        ]);

        ArtistSetting::create([
            'artist_id' => $artist->id,
            'display_name' => 'Artist Google',
        ]);

        return $artist;
    }

    private function artistUser(Artist $artist): User
    {
        return User::create([
            'name' => 'Artist User',
            'email' => 'google-'.$artist->id.'@vaif.test',
            'password' => Hash::make('password'),
            'role' => User::ROLE_ARTIST,
            'artist_id' => $artist->id,
        ]);
    }

    private function mockGoogle(callable $extra = null): void
    {
        $this->mock(GoogleCalendarService::class, function (MockInterface $mock) use ($extra) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('serviceAccountEmail')->andReturn('vaif-calendar@example.iam.gserviceaccount.com');

            if ($extra) {
                $extra($mock);
            }
        });
    }

    public function test_an_artist_connects_a_shared_google_calendar(): void
    {
        $artist = $this->artist();
        $user = $this->artistUser($artist);

        $this->mockGoogle(function (MockInterface $mock) {
            $mock->shouldReceive('getCalendar')
                ->once()
                ->with('artist@example.com')
                ->andReturn([
                    'id' => 'artist@example.com',
                    'summary' => 'Agenda da Tattoo',
                    'timezone' => 'America/Sao_Paulo',
                ]);
        });

        $this->actingAs($user)->get('/config')
            ->assertOk()
            ->assertSee('Google Agenda')
            ->assertSee('vaif-calendar@example.iam.gserviceaccount.com');

        $this->actingAs($user)
            ->post('/config/google', ['calendar_id' => 'artist@example.com'])
            ->assertRedirect();

        $artist->refresh();

        $this->assertSame('artist@example.com', $artist->google_calendar_id);
        $this->assertSame('Agenda da Tattoo', $artist->google_calendar_summary);
        $this->assertSame('America/Sao_Paulo', $artist->google_calendar_timezone);
        $this->assertNotNull($artist->google_connected_at);
    }

    public function test_it_rejects_a_read_only_calendar(): void
    {
        $artist = $this->artist();
        $user = $this->artistUser($artist);

        $this->mockGoogle(function (MockInterface $mock) {
            $mock->shouldReceive('getCalendar')->once()->andReturn([
                'id' => 'artist@example.com',
                'summary' => 'Agenda da Tattoo',
                'timezone' => 'America/Sao_Paulo',
                'access_role' => 'reader',
            ]);
        });

        $this->actingAs($user)
            ->post('/config/google', ['calendar_id' => 'artist@example.com'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNull($artist->refresh()->google_calendar_id);
    }

    public function test_it_lists_calendars_shared_with_the_service_account(): void
    {
        $artist = $this->artist();
        $user = $this->artistUser($artist);
        $user->forceFill(['email' => 'artist@example.com'])->save();

        $this->mockGoogle(function (MockInterface $mock) {
            $mock->shouldReceive('listCalendars')->once()->andReturn([
                [
                    'id' => 'artist@example.com',
                    'summary' => 'Agenda da Tattoo',
                    'timezone' => 'America/Sao_Paulo',
                    'access_role' => 'writer',
                    'primary' => false,
                ],
            ]);
        });

        $this->actingAs($user)
            ->post('/config/google/detect')
            ->assertRedirect()
            ->assertSessionHas('google_calendars');
    }

    public function test_an_artist_only_sees_calendars_matching_their_account(): void
    {
        $artist = $this->artist();
        $user = $this->artistUser($artist);
        $user->forceFill(['email' => 'artist@example.com'])->save();

        $this->mockGoogle(function (MockInterface $mock) {
            $mock->shouldReceive('listCalendars')->once()->andReturn([
                [
                    'id' => 'artist@example.com',
                    'summary' => 'Minha agenda',
                    'timezone' => 'America/Sao_Paulo',
                    'access_role' => 'writer',
                    'primary' => true,
                ],
                [
                    'id' => 'another-artist@example.com',
                    'summary' => 'Outra agenda',
                    'timezone' => 'America/Sao_Paulo',
                    'access_role' => 'writer',
                    'primary' => false,
                ],
            ]);
        });

        $this->actingAs($user)
            ->post('/config/google/detect')
            ->assertRedirect()
            ->assertSessionHas('google_calendars', function (array $calendars) {
                return count($calendars) === 1
                    && $calendars[0]['id'] === 'artist@example.com';
            });
    }

    public function test_disconnecting_keeps_the_google_events_but_clears_the_link(): void
    {
        $artist = $this->artist();
        $artist->forceFill([
            'google_calendar_id' => 'artist@example.com',
            'google_calendar_summary' => 'Agenda da Tattoo',
            'google_connected_at' => now(),
        ])->save();
        $user = $this->artistUser($artist);

        $lead = Lead::create([
            'id' => (string) Str::uuid(),
            'artist_id' => $artist->id,
            'name' => 'Cliente',
            'phone_e164' => '+5511900000000',
        ]);

        $entry = CalendarEntry::create([
            'id' => (string) Str::uuid(),
            'artist_id' => $artist->id,
            'kind' => 'booking',
            'lead_id' => $lead->id,
            'project_id' => null,
            'start_at' => now()->addDay(),
            'end_at' => now()->addDay()->addHour(),
            'active' => true,
            'google_event_id' => 'evt-1',
            'google_sync_pending' => false,
        ]);

        $this->actingAs($user)
            ->post('/config/google/disconnect')
            ->assertRedirect();

        $this->assertNull($artist->refresh()->google_calendar_id);
        $this->assertSame('evt-1', $entry->refresh()->google_event_id);
        $this->assertFalse($entry->google_sync_pending);
    }
}
