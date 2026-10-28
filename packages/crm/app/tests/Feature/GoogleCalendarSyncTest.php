<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\CalendarEntry;
use App\Models\Lead;
use App\Models\Project;
use App\Services\Google\GoogleCalendarException;
use App\Services\Google\GoogleCalendarService;
use App\Services\Google\GoogleCalendarSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

class GoogleCalendarSyncTest extends TestCase
{
    use RefreshDatabase;

    private function artist(): Artist
    {
        return Artist::create([
            'name' => 'Artist Google',
            'status' => 'live',
            'timezone' => 'America/Sao_Paulo',
            'wa_session_slug' => 'google-sync',
            'google_calendar_id' => 'artist@example.com',
            'google_calendar_summary' => 'Agenda da Tattoo',
            'google_calendar_timezone' => 'America/Sao_Paulo',
            'google_connected_at' => now(),
        ]);
    }

    /**
     * @return array{0: Lead, 1: Project, 2: CalendarEntry}
     */
    private function booking(Artist $artist, array $overrides = []): array
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
            'placement' => 'braco',
            'style' => 'Fineline',
        ]);

        $entry = CalendarEntry::create(array_merge([
            'id' => (string) Str::uuid(),
            'artist_id' => $artist->id,
            'kind' => 'booking',
            'lead_id' => $lead->id,
            'project_id' => $project->id,
            'start_at' => now()->addDays(2)->setTime(14, 0),
            'end_at' => now()->addDays(2)->setTime(16, 0),
            'active' => true,
            'google_sync_pending' => true,
        ], $overrides));

        return [$lead, $project, $entry];
    }

    public function test_a_pending_booking_is_created_in_google(): void
    {
        $artist = $this->artist();
        [, , $entry] = $this->booking($artist);

        $captured = null;

        $this->mock(GoogleCalendarService::class, function (MockInterface $mock) use (&$captured) {
            $mock->shouldReceive('createEvent')
                ->once()
                ->withArgs(function (string $calendarId, array $payload) use (&$captured) {
                    $captured = $payload;

                    return $calendarId === 'artist@example.com';
                })
                ->andReturn(['id' => 'evt-1']);
        });

        $counts = app(GoogleCalendarSync::class)->syncArtist($artist);

        $this->assertSame(1, $counts['created']);
        $this->assertSame(0, $counts['failed']);

        $entry->refresh();
        $this->assertSame('evt-1', $entry->google_event_id);
        $this->assertFalse($entry->google_sync_pending);
        $this->assertNull($entry->google_sync_error);

        $this->assertSame('Tatuagem — João', $captured['summary']);
        $this->assertSame('America/Sao_Paulo', $captured['start']['timeZone']);
        $this->assertStringContainsString('Telefone: +5521999553136', $captured['description']);
        $this->assertSame($entry->id, $captured['extendedProperties']['private']['vaif_booking_id']);
    }

    public function test_the_artist_timezone_wins_over_the_google_calendar_timezone(): void
    {
        $artist = $this->artist();
        $artist->forceFill(['google_calendar_timezone' => 'Europe/Berlin'])->save();

        [, , $entry] = $this->booking($artist);

        $payload = app(GoogleCalendarSync::class)->eventPayload($artist->fresh(), $entry);

        $this->assertSame('America/Sao_Paulo', $payload['start']['timeZone']);
        $this->assertStringContainsString('-03:00', $payload['start']['dateTime']);
    }

    public function test_a_rescheduled_booking_updates_the_existing_event(): void
    {
        $artist = $this->artist();
        [, , $entry] = $this->booking($artist, ['google_event_id' => 'evt-old']);

        $this->mock(GoogleCalendarService::class, function (MockInterface $mock) {
            $mock->shouldReceive('updateEvent')
                ->once()
                ->with('artist@example.com', 'evt-old', \Mockery::on(fn (array $payload) => $payload['summary'] === 'Tatuagem — João'))
                ->andReturn(['id' => 'evt-old']);
        });

        $counts = app(GoogleCalendarSync::class)->syncArtist($artist);

        $this->assertSame(1, $counts['updated']);
        $this->assertSame('evt-old', $entry->refresh()->google_event_id);
    }

    public function test_a_cancelled_booking_deletes_the_event(): void
    {
        $artist = $this->artist();
        [, , $entry] = $this->booking($artist, [
            'active' => false,
            'google_event_id' => 'evt-1',
        ]);

        $this->mock(GoogleCalendarService::class, function (MockInterface $mock) {
            $mock->shouldReceive('deleteEvent')
                ->once()
                ->with('artist@example.com', 'evt-1');
        });

        $counts = app(GoogleCalendarSync::class)->syncArtist($artist);

        $this->assertSame(1, $counts['deleted']);

        $entry->refresh();
        $this->assertNull($entry->google_event_id);
        $this->assertFalse($entry->google_sync_pending);
    }

    public function test_a_google_failure_is_recorded_and_left_pending(): void
    {
        $artist = $this->artist();
        [, , $entry] = $this->booking($artist);

        $this->mock(GoogleCalendarService::class, function (MockInterface $mock) {
            $mock->shouldReceive('createEvent')
                ->once()
                ->andThrow(new GoogleCalendarException('Google recusou o evento.'));
        });

        $counts = app(GoogleCalendarSync::class)->syncArtist($artist);

        $this->assertSame(1, $counts['failed']);

        $entry->refresh();
        $this->assertTrue($entry->google_sync_pending);
        $this->assertSame('Google recusou o evento.', $entry->google_sync_error);
    }

    public function test_the_command_syncs_connected_artists(): void
    {
        $artist = $this->artist();
        $this->booking($artist);

        $this->mock(GoogleCalendarService::class, function (MockInterface $mock) {
            $mock->shouldReceive('createEvent')->once()->andReturn(['id' => 'evt-1']);
        });

        $this->artisan('calendar:sync-google')
            ->expectsOutputToContain('1 created')
            ->assertExitCode(0);
    }
}
