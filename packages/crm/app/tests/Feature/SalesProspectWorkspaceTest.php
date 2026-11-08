<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesProspectWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private User $sales;

    private User $staff;

    private Campaign $campaign;

    private Prospect $prospect;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sales = User::create([
            'name' => 'Sales Rep',
            'email' => 'sales@vaif.test',
            'password' => 'password-123',
            'role' => User::ROLE_SALES,
        ]);

        $this->staff = User::create([
            'name' => 'Staff',
            'email' => 'staff@vaif.test',
            'password' => 'password-123',
            'role' => User::ROLE_STAFF,
        ]);

        $this->campaign = Campaign::create([
            'name' => 'Coorte Fundadora',
            'channel' => Campaign::CHANNEL_META,
            'status' => Campaign::STATUS_ACTIVE,
        ]);

        $this->prospect = Prospect::create([
            'name' => 'Artista Workspace',
            'whatsapp_e164' => '+5511999990001',
            'whatsapp_raw' => '(11) 99999-0001',
            'instagram' => 'artistaworkspace',
            'source' => 'calculadora',
            'status' => Prospect::STATUS_ACTIVE,
            'stage_key' => 'novo',
            'revenue_monthly_cents' => 1_000_000,
            'ticket_cents' => 150_000,
            'sessions_monthly' => 10,
            'admin_hours_day' => 2,
            'owner_id' => $this->sales->id,
            'campaign_id' => $this->campaign->id,
        ]);
    }

    public function test_create_form_renders(): void
    {
        $this->actingAs($this->sales)
            ->get(route('sales.prospects.create'))
            ->assertOk()
            ->assertSee('Novo prospect');
    }

    public function test_edit_form_renders(): void
    {
        $this->actingAs($this->sales)
            ->get(route('sales.prospects.edit', $this->prospect))
            ->assertOk()
            ->assertSee('Editar prospect');
    }

    public function test_detail_page_shows_prospect_and_timeline(): void
    {
        $this->prospect->activities()->create([
            'type' => 'note',
            'title' => 'Primeiro contato',
            'body' => 'Retornar amanhã.',
            'completed_at' => now(),
        ]);

        $this->actingAs($this->sales)
            ->get(route('sales.prospects.show', $this->prospect))
            ->assertOk()
            ->assertSee('Artista Workspace')
            ->assertSee('697')
            ->assertSee('Primeiro contato')
            ->assertSee('Retornar amanhã.');
    }

    public function test_log_note_activity(): void
    {
        $this->actingAs($this->sales)
            ->post(route('sales.prospects.activities.store', $this->prospect), [
                'type' => 'note',
                'body' => 'Falei com o artista.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('prospect_activities', [
            'prospect_id' => $this->prospect->id,
            'type' => 'note',
            'body' => 'Falei com o artista.',
            'user_id' => $this->sales->id,
        ]);
    }

    public function test_complete_task(): void
    {
        $task = $this->prospect->activities()->create([
            'type' => 'task',
            'title' => 'Ligar amanhã',
            'due_at' => now()->addDay(),
        ]);

        $this->actingAs($this->sales)
            ->post(route('sales.prospects.activities.complete', [$this->prospect, $task]))
            ->assertRedirect();

        $this->assertNotNull($task->fresh()->completed_at);
    }

    public function test_stage_transition_logs_activity_and_sets_next_action(): void
    {
        $this->actingAs($this->sales)
            ->post(route('sales.prospects.stage', $this->prospect), [
                'stage_key' => 'qualificado',
                'note' => 'Fit confirmado.',
            ])
            ->assertRedirect();

        $prospect = $this->prospect->fresh();

        $this->assertSame('qualificado', $prospect->stage_key);
        $this->assertNotNull($prospect->next_action_at);

        $this->assertDatabaseHas('prospect_activities', [
            'prospect_id' => $this->prospect->id,
            'type' => 'stage_change',
        ]);
    }

    public function test_lost_requires_a_reason(): void
    {
        $this->actingAs($this->sales)
            ->post(route('sales.prospects.stage', $this->prospect), [
                'stage_key' => 'perdido',
            ])
            ->assertSessionHasErrors('lost_reason');

        $this->assertSame('novo', $this->prospect->fresh()->stage_key);
    }

    public function test_ganho_is_blocked_until_handoff_exists(): void
    {
        $this->actingAs($this->sales)
            ->post(route('sales.prospects.stage', $this->prospect), [
                'stage_key' => 'ganho',
            ])
            ->assertSessionHasErrors('stage_key');

        $this->assertSame('novo', $this->prospect->fresh()->stage_key);
    }

    public function test_sales_can_create_a_manual_prospect(): void
    {
        $this->actingAs($this->sales)
            ->post(route('sales.prospects.store'), [
                'name' => 'Novo Artista',
                'whatsapp' => '(11) 98888-7777',
                'revenue_monthly' => 15000,
                'ticket' => 1500,
                'sessions_monthly' => 10,
                'admin_hours_day' => 2,
                'source' => 'manual',
            ])
            ->assertRedirect();

        $created = Prospect::where('whatsapp_e164', '+5511988887777')->firstOrFail();

        $this->assertSame(Prospect::STATUS_ACTIVE, $created->status);
        $this->assertSame('novo', $created->stage_key);
        $this->assertSame($this->sales->id, $created->owner_id);
        $this->assertSame(1_500_000, $created->revenue_monthly_cents);
        $this->assertSame(Prospect::BAND_RETAINER, $created->revenue_band);
    }

    public function test_sales_cannot_reassign_to_another_owner(): void
    {
        $other = $this->otherSales();

        $this->actingAs($this->sales)
            ->put(route('sales.prospects.update', $this->prospect), [
                'name' => 'Artista Workspace',
                'whatsapp' => '(11) 99999-0001',
                'owner_id' => $other->id,
            ])
            ->assertSessionHasErrors('owner_id');

        $this->assertSame($this->sales->id, $this->prospect->fresh()->owner_id);
    }

    public function test_staff_can_reassign_an_owner(): void
    {
        $other = $this->otherSales();

        $this->actingAs($this->staff)
            ->put(route('sales.prospects.update', $this->prospect), [
                'name' => 'Artista Workspace',
                'whatsapp' => '(11) 99999-0001',
                'owner_id' => $other->id,
            ])
            ->assertRedirect();

        $this->assertSame($other->id, $this->prospect->fresh()->owner_id);
    }

    public function test_edit_updates_fields_and_next_action(): void
    {
        $this->actingAs($this->sales)
            ->put(route('sales.prospects.update', $this->prospect), [
                'name' => 'Artista Workspace',
                'whatsapp' => '(11) 99999-0001',
                'revenue_monthly' => 20000,
                'ticket' => 2000,
                'sessions_monthly' => 12,
                'admin_hours_day' => 3,
                'next_action_at' => now()->addDays(2)->format('Y-m-d H:i:s'),
                'next_action_note' => 'Ligar para fechar.',
            ])
            ->assertRedirect();

        $prospect = $this->prospect->fresh();

        $this->assertSame(2_000_000, $prospect->revenue_monthly_cents);
        $this->assertSame(200_000, $prospect->ticket_cents);
        $this->assertSame(12, $prospect->sessions_monthly);
        $this->assertSame(Prospect::BAND_RETAINER, $prospect->revenue_band);
        $this->assertSame('Ligar para fechar.', $prospect->next_action_note);
        $this->assertNotNull($prospect->next_action_at);
    }

    public function test_sales_cannot_work_another_sellers_prospect(): void
    {
        $other = $this->otherSales();
        $this->prospect->update(['owner_id' => $other->id]);

        $this->actingAs($this->sales)
            ->get(route('sales.prospects.edit', $this->prospect))
            ->assertForbidden();

        $this->actingAs($this->sales)
            ->post(route('sales.prospects.stage', $this->prospect), [
                'stage_key' => 'qualificado',
            ])
            ->assertForbidden();
    }

    public function test_update_next_action_creates_a_timeline_entry(): void
    {
        $this->actingAs($this->sales)
            ->post(route('sales.prospects.next-action', $this->prospect), [
                'next_action_at' => now()->addDays(2)->format('Y-m-d H:i:s'),
                'next_action_note' => 'Confirmar call.',
            ])
            ->assertRedirect();

        $prospect = $this->prospect->fresh();

        $this->assertNotNull($prospect->next_action_at);
        $this->assertSame('Confirmar call.', $prospect->next_action_note);

        $this->assertDatabaseHas('prospect_activities', [
            'prospect_id' => $this->prospect->id,
            'type' => 'note',
            'title' => 'Próxima ação atualizada',
        ]);
    }

    private function otherSales(): User
    {
        return User::create([
            'name' => 'Other Sales',
            'email' => 'other.sales@vaif.test',
            'password' => 'password-123',
            'role' => User::ROLE_SALES,
        ]);
    }
}
