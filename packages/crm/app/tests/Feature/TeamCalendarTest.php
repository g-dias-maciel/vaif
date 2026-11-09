<?php

namespace Tests\Feature;

use App\Models\Prospect;
use App\Models\TeamEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamCalendarTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $sales;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Admin Agenda',
            'email' => 'admin-agenda@vaif.test',
            'password' => 'password-123',
            'role' => User::ROLE_STAFF,
        ]);

        $this->sales = User::create([
            'name' => 'Vendas Agenda',
            'email' => 'vendas-agenda@vaif.test',
            'password' => 'password-123',
            'role' => User::ROLE_SALES,
        ]);
    }

    public function test_staff_can_open_the_calendar_and_sales_cannot(): void
    {
        $this->actingAs($this->admin)
            ->get(route('calendar.index'))
            ->assertOk()
            ->assertSee('Calendário');

        $this->actingAs($this->sales)
            ->get(route('calendar.index'))
            ->assertForbidden();
    }

    public function test_staff_can_create_edit_and_delete_a_team_event(): void
    {
        $start = now()->startOfMonth()->addDays(9)->setTime(10, 0);
        $end = $start->copy()->addHour();

        $this->actingAs($this->admin)
            ->post(route('calendar.events.store'), [
                'title' => 'Planejamento estratégico',
                'type' => TeamEvent::TYPE_STRATEGY,
                'starts_at' => $start->format('Y-m-d\TH:i'),
                'ends_at' => $end->format('Y-m-d\TH:i'),
                'owner_id' => $this->admin->id,
                'location' => 'Escritório',
                'notes' => 'Revisão do trimestre',
            ])
            ->assertRedirect(route('calendar.index', ['month' => $start->format('Y-m')]));

        $event = TeamEvent::firstOrFail();

        $this->assertSame('Planejamento estratégico', $event->title);
        $this->assertSame(TeamEvent::TYPE_STRATEGY, $event->type);
        $this->assertSame($this->admin->id, $event->owner_id);

        $this->actingAs($this->admin)
            ->get(route('calendar.index', ['month' => $start->format('Y-m')]))
            ->assertOk()
            ->assertSee('Planejamento estratégico');

        $this->actingAs($this->admin)
            ->put(route('calendar.events.update', $event), [
                'title' => 'Planejamento atualizado',
                'type' => TeamEvent::TYPE_STRATEGY,
                'starts_at' => $start->format('Y-m-d\TH:i'),
                'ends_at' => $end->format('Y-m-d\TH:i'),
                'owner_id' => $this->admin->id,
            ])
            ->assertRedirect(route('calendar.index', ['month' => $start->format('Y-m')]));

        $this->assertSame('Planejamento atualizado', $event->fresh()->title);

        $this->actingAs($this->admin)
            ->delete(route('calendar.events.destroy', $event))
            ->assertRedirect(route('calendar.index', ['month' => $start->format('Y-m')]));

        $this->assertNull(TeamEvent::find($event->id));
    }

    public function test_end_must_be_after_start(): void
    {
        $start = now()->startOfMonth()->addDays(9)->setTime(10, 0);

        $this->actingAs($this->admin)
            ->post(route('calendar.events.store'), [
                'title' => 'Inválido',
                'type' => TeamEvent::TYPE_OTHER,
                'starts_at' => $start->format('Y-m-d\TH:i'),
                'ends_at' => $start->format('Y-m-d\TH:i'),
                'owner_id' => $this->admin->id,
            ])
            ->assertSessionHasErrors('ends_at');

        $this->assertSame(0, TeamEvent::count());
    }

    public function test_filters_by_type_owner_and_month(): void
    {
        $start = now()->startOfMonth()->addDays(9)->setTime(10, 0);
        $end = $start->copy()->addHour();

        TeamEvent::create([
            'title' => 'Reunião de vendas X',
            'type' => TeamEvent::TYPE_SALES,
            'starts_at' => $start,
            'ends_at' => $end,
            'owner_id' => $this->sales->id,
        ]);

        TeamEvent::create([
            'title' => 'Estratégia Y',
            'type' => TeamEvent::TYPE_STRATEGY,
            'starts_at' => $start->copy()->addDay(),
            'ends_at' => $end->copy()->addDay(),
            'owner_id' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->get(route('calendar.index', ['month' => $start->format('Y-m'), 'type' => 'strategy']))
            ->assertOk()
            ->assertSee('Estratégia Y')
            ->assertDontSee('Reunião de vendas X');

        $this->actingAs($this->admin)
            ->get(route('calendar.index', ['month' => $start->format('Y-m'), 'owner_id' => $this->sales->id]))
            ->assertOk()
            ->assertSee('Reunião de vendas X')
            ->assertDontSee('Estratégia Y');

        $nextMonth = $start->copy()->addMonthNoOverflow()->format('Y-m');

        $this->actingAs($this->admin)
            ->get(route('calendar.index', ['month' => $nextMonth]))
            ->assertOk()
            ->assertDontSee('Reunião de vendas X')
            ->assertDontSee('Estratégia Y');
    }

    public function test_sales_calls_from_prospects_appear_automatically(): void
    {
        $prospect = Prospect::create([
            'name' => 'Prospect da Agenda',
            'whatsapp_e164' => '+5511955554444',
            'source' => 'notion',
            'status' => Prospect::STATUS_BACKLOG,
            'owner_id' => $this->sales->id,
            'booked_call_at' => now()->startOfMonth()->addDays(9)->setTime(14, 0),
        ]);

        $this->actingAs($this->admin)
            ->get(route('calendar.index', ['month' => now()->format('Y-m')]))
            ->assertOk()
            ->assertSee('Call — Prospect da Agenda')
            ->assertSee('Call registrada no prospect');

        $this->actingAs($this->admin)
            ->get(route('calendar.index', ['month' => now()->format('Y-m'), 'type' => 'strategy']))
            ->assertOk()
            ->assertDontSee('Call — Prospect da Agenda');
    }

    public function test_sales_cannot_manage_team_events(): void
    {
        $start = now()->startOfMonth()->addDays(9)->setTime(10, 0);
        $end = $start->copy()->addHour();

        $event = TeamEvent::create([
            'title' => 'Reunião restrita',
            'type' => TeamEvent::TYPE_OTHER,
            'starts_at' => $start,
            'ends_at' => $end,
            'owner_id' => $this->admin->id,
        ]);

        $this->actingAs($this->sales)->get(route('calendar.events.create'))->assertForbidden();
        $this->actingAs($this->sales)->post(route('calendar.events.store'), [])->assertForbidden();
        $this->actingAs($this->sales)->get(route('calendar.events.edit', $event))->assertForbidden();
        $this->actingAs($this->sales)->put(route('calendar.events.update', $event), [])->assertForbidden();
        $this->actingAs($this->sales)->delete(route('calendar.events.destroy', $event))->assertForbidden();

        $this->assertNotNull(TeamEvent::find($event->id));
    }
}
