<?php

namespace Tests\Feature;

use App\Models\TeamEvent;
use App\Models\User;
use App\Services\Google\GoogleCalendarService;
use App\Services\Google\TeamGoogleCalendarSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamGoogleCalendarTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $sales;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Admin Google',
            'email' => 'admin-google@vaif.test',
            'password' => 'password-123',
            'role' => User::ROLE_STAFF,
        ]);

        $this->sales = User::create([
            'name' => 'Vendas Google',
            'email' => 'vendas-google@vaif.test',
            'password' => 'password-123',
            'role' => User::ROLE_SALES,
        ]);
    }

    public function test_staff_can_connect_a_team_members_google_calendar(): void
    {
        $event = $this->event(['owner_id' => $this->sales->id]);

        $this->mock(GoogleCalendarService::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('getCalendar')->with('cal-1')->andReturn([
                'id' => 'cal-1',
                'summary' => 'Agenda Vendas',
                'timezone' => 'America/Sao_Paulo',
                'access_role' => 'writer',
            ]);
            $mock->shouldReceive('canWrite')->with('cal-1')->andReturn(true);
            $mock->shouldReceive('serviceAccountEmail')->andReturn('service@vaif.test');
        });

        $this->actingAs($this->admin)
            ->post(route('team.google.connect', $this->sales), ['calendar_id' => 'cal-1'])
            ->assertRedirect();

        $this->sales->refresh();

        $this->assertSame('cal-1', $this->sales->google_calendar_id);
        $this->assertSame('Agenda Vendas', $this->sales->google_calendar_summary);
        $this->assertSame('America/Sao_Paulo', $this->sales->calendarTimezone());
        $this->assertNotNull($this->sales->google_connected_at);
        $this->assertTrue($event->fresh()->google_sync_pending);
    }

    public function test_connect_rejects_a_read_only_calendar(): void
    {
        $this->mock(GoogleCalendarService::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('getCalendar')->andReturn([
                'id' => 'cal-1',
                'summary' => 'Agenda Vendas',
                'timezone' => 'America/Sao_Paulo',
                'access_role' => 'reader',
            ]);
            $mock->shouldReceive('canWrite')->andReturn(false);
        });

        $this->actingAs($this->admin)
            ->post(route('team.google.connect', $this->sales), ['calendar_id' => 'cal-1'])
            ->assertSessionHas('error');

        $this->assertNull($this->sales->fresh()->google_calendar_id);
    }

    public function test_detect_lists_shared_calendars(): void
    {
        $this->mock(GoogleCalendarService::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('listCalendars')->andReturn([
                ['id' => 'cal-1', 'summary' => 'Agenda Vendas', 'timezone' => 'America/Sao_Paulo', 'access_role' => 'writer', 'primary' => true],
            ]);
            $mock->shouldReceive('serviceAccountEmail')->andReturn('service@vaif.test');
        });

        $this->actingAs($this->admin)
            ->post(route('team.google.detect', $this->sales))
            ->assertSessionHas('google_calendars');
    }

    public function test_disconnect_marks_owned_events_for_google_deletion(): void
    {
        $this->sales->forceFill([
            'google_calendar_id' => 'cal-1',
            'google_calendar_summary' => 'Agenda Vendas',
            'google_calendar_timezone' => 'America/Sao_Paulo',
            'google_connected_at' => now(),
        ])->save();

        $event = $this->event([
            'owner_id' => $this->sales->id,
            'google_event_id' => 'g-1',
            'google_calendar_id' => 'cal-1',
            'google_sync_pending' => false,
        ]);

        $this->actingAs($this->admin)
            ->delete(route('team.google.disconnect', $this->sales))
            ->assertRedirect();

        $this->assertNull($this->sales->fresh()->google_calendar_id);
        $this->assertTrue($event->fresh()->google_sync_pending);
    }

    public function test_sync_creates_updates_and_deletes_google_events(): void
    {
        $this->sales->forceFill([
            'google_calendar_id' => 'cal-1',
            'google_calendar_timezone' => 'America/Sao_Paulo',
            'google_connected_at' => now(),
        ])->save();

        $created = $this->event([
            'owner_id' => $this->sales->id,
            'google_sync_pending' => true,
        ]);

        $updated = $this->event([
            'owner_id' => $this->sales->id,
            'title' => 'Reunião existente',
            'google_event_id' => 'g-existing',
            'google_calendar_id' => 'cal-1',
            'google_sync_pending' => true,
        ]);

        $deleted = $this->event([
            'owner_id' => $this->sales->id,
            'title' => 'Reunião removida',
            'google_event_id' => 'g-deleted',
            'google_calendar_id' => 'cal-1',
            'google_sync_pending' => true,
        ]);
        $deleted->delete();

        $this->mock(GoogleCalendarService::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('createEvent')
                ->once()
                ->withArgs(fn (string $calendarId, array $payload): bool => $calendarId === 'cal-1'
                    && ($payload['extendedProperties']['private']['vaif_team_event_id'] ?? null) !== null)
                ->andReturn(['id' => 'g-created']);
            $mock->shouldReceive('updateEvent')
                ->once()
                ->withArgs(fn (string $calendarId, string $eventId, array $payload): bool => $calendarId === 'cal-1'
                    && $eventId === 'g-existing'
                    && $payload['summary'] === 'Reunião existente')
                ->andReturn([]);
            $mock->shouldReceive('deleteEvent')
                ->once()
                ->with('cal-1', 'g-deleted');
        });

        $counts = app(TeamGoogleCalendarSync::class)->syncPending();

        $this->assertSame(1, $counts['created']);
        $this->assertSame(1, $counts['updated']);
        $this->assertSame(1, $counts['deleted']);
        $this->assertSame(0, $counts['failed']);

        $created->refresh();
        $this->assertSame('g-created', $created->google_event_id);
        $this->assertSame('cal-1', $created->google_calendar_id);
        $this->assertFalse($created->google_sync_pending);
        $this->assertNotNull($created->google_synced_at);

        $this->assertSame('g-existing', $updated->fresh()->google_event_id);
        $this->assertNull(TeamEvent::withTrashed()->find($deleted->id));
    }

    public function test_owner_change_moves_the_google_event_to_the_new_calendar(): void
    {
        $newOwner = User::create([
            'name' => 'Novo Responsável',
            'email' => 'novo-responsavel@vaif.test',
            'password' => 'password-123',
            'role' => User::ROLE_SALES,
            'google_calendar_id' => 'cal-2',
            'google_calendar_timezone' => 'America/Sao_Paulo',
        ]);

        $event = $this->event([
            'owner_id' => $newOwner->id,
            'google_event_id' => 'g-old',
            'google_calendar_id' => 'cal-1',
            'google_sync_pending' => true,
        ]);

        $this->mock(GoogleCalendarService::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('deleteEvent')->once()->with('cal-1', 'g-old');
            $mock->shouldReceive('createEvent')
                ->once()
                ->withArgs(fn (string $calendarId): bool => $calendarId === 'cal-2')
                ->andReturn(['id' => 'g-new']);
        });

        $counts = app(TeamGoogleCalendarSync::class)->syncPending();

        $this->assertSame(1, $counts['created']);
        $this->assertSame('g-new', $event->fresh()->google_event_id);
        $this->assertSame('cal-2', $event->fresh()->google_calendar_id);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function event(array $overrides = []): TeamEvent
    {
        $start = now()->startOfMonth()->addDays(9)->setTime(10, 0);

        return TeamEvent::create(array_merge([
            'title' => 'Reunião Google',
            'type' => TeamEvent::TYPE_SALES,
            'starts_at' => $start,
            'ends_at' => $start->copy()->addHour(),
            'owner_id' => $this->admin->id,
        ], $overrides));
    }
}
