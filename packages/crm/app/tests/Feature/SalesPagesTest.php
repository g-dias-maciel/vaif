<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $sales;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sales = User::create([
            'name' => 'Sales Rep',
            'email' => 'sales@vaif.test',
            'password' => 'password-123',
            'role' => User::ROLE_SALES,
        ]);
    }

    public function test_dashboard_shows_active_counts_and_mrr(): void
    {
        [$active, $backlog, $campaign] = $this->seedPipeline();

        $active->contracts()->create([
            'plan' => 'semestral',
            'monthly_value_cents' => 180_000,
            'total_value_cents' => 990_000,
            'start_date' => now()->toDateString(),
            'status' => 'active',
            'signed_at' => now(),
        ]);

        $this->actingAs($this->sales)
            ->get('/sales')
            ->assertOk()
            ->assertSee('Cockpit de vendas')
            ->assertSee('Artista Ativo')
            ->assertSee('R$ 1.800');
    }

    public function test_pipeline_lists_active_prospects_only(): void
    {
        [$active, $backlog] = $this->seedPipeline();

        $this->actingAs($this->sales)
            ->get('/sales/pipeline')
            ->assertOk()
            ->assertSee('Artista Ativo')
            ->assertDontSee('Artista Backlog');
    }

    public function test_backlog_lists_backlog_prospects_only(): void
    {
        [$active, $backlog] = $this->seedPipeline();

        $this->actingAs($this->sales)
            ->get('/sales/backlog')
            ->assertOk()
            ->assertSee('Artista Backlog')
            ->assertDontSee('Artista Ativo');
    }

    public function test_promote_moves_a_backlog_prospect_into_the_pipeline(): void
    {
        [$active, $backlog] = $this->seedPipeline();

        $this->actingAs($this->sales)
            ->post(route('sales.backlog.promote', $backlog))
            ->assertRedirect();

        $backlog->refresh();

        $this->assertTrue($backlog->isActive());
        $this->assertSame('novo', $backlog->stage_key);
        $this->assertSame($this->sales->id, $backlog->owner_id);
        $this->assertNotNull($backlog->next_action_at);

        $this->assertDatabaseHas('prospect_activities', [
            'prospect_id' => $backlog->id,
            'type' => 'stage_change',
            'title' => 'Promovido do backlog',
        ]);
    }

    public function test_campaign_page_lists_campaigns_and_cpl(): void
    {
        [$active, $backlog, $campaign] = $this->seedPipeline();

        $this->actingAs($this->sales)
            ->get('/sales/campaigns')
            ->assertOk()
            ->assertSee('Coorte Fundadora')
            ->assertSee('R$ 3.000')
            ->assertSee('R$ 1.500');
    }

    /**
     * @return array{0: Prospect, 1: Prospect, 2: Campaign}
     */
    private function seedPipeline(): array
    {
        $campaign = Campaign::create([
            'name' => 'Coorte Fundadora',
            'channel' => Campaign::CHANNEL_META,
            'status' => Campaign::STATUS_ACTIVE,
            'spend_cents' => 300_000,
        ]);

        $active = Prospect::create([
            'name' => 'Artista Ativo',
            'whatsapp_e164' => '+5511999990001',
            'instagram' => 'artistaativo',
            'source' => 'calculadora',
            'status' => Prospect::STATUS_ACTIVE,
            'stage_key' => 'novo',
            'revenue_monthly_cents' => 1_000_000,
            'ticket_cents' => 150_000,
            'sessions_monthly' => 10,
            'admin_hours_day' => 2,
            'campaign_id' => $campaign->id,
            'next_action_at' => now()->addDay(),
        ]);

        $backlog = Prospect::create([
            'name' => 'Artista Backlog',
            'whatsapp_e164' => '+5511999990002',
            'instagram' => 'artistabacklog',
            'source' => 'calculadora',
            'status' => Prospect::STATUS_BACKLOG,
            'revenue_monthly_cents' => 2_000_000,
            'ticket_cents' => 200_000,
            'sessions_monthly' => 12,
            'admin_hours_day' => 3,
            'campaign_id' => $campaign->id,
            'booked_call_at' => now()->subDays(10),
        ]);

        return [$active, $backlog, $campaign];
    }
}
