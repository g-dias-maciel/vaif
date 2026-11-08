<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Prospect;
use App\Models\ProspectStage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesCrmSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_pipeline_stages_are_seeded_in_order(): void
    {
        $keys = ProspectStage::query()->orderBy('sort_order')->pluck('key')->all();

        $this->assertSame([
            'novo',
            'qualificado',
            'call_agendada',
            'call_realizada',
            'proposta',
            'ganho',
            'perdido',
            'nutricao',
        ], $keys);
    }

    public function test_revenue_bands_follow_the_offer_boundaries(): void
    {
        $this->assertSame(Prospect::BAND_UNKNOWN, Prospect::bandForCents(null));
        $this->assertSame(Prospect::BAND_UNKNOWN, Prospect::bandForCents(0));
        $this->assertSame(Prospect::BAND_NURTURE, Prospect::bandForCents(399_999));
        $this->assertSame(Prospect::BAND_ENTRY, Prospect::bandForCents(400_000));
        $this->assertSame(Prospect::BAND_ENTRY, Prospect::bandForCents(1_499_999));
        $this->assertSame(Prospect::BAND_RETAINER, Prospect::bandForCents(1_500_000));
        $this->assertSame(Prospect::BAND_RETAINER, Prospect::bandForCents(7_499_999));
        $this->assertSame(Prospect::BAND_FULL_SERVICE, Prospect::bandForCents(7_500_000));
    }

    public function test_prospect_computes_band_and_score_and_relates_to_campaign_and_owner(): void
    {
        $owner = User::create([
            'name' => 'Sales Rep',
            'email' => 'sales@vaif.test',
            'password' => 'password',
            'role' => User::ROLE_SALES,
        ]);

        $campaign = Campaign::create([
            'name' => 'Coorte Fundadora',
            'channel' => Campaign::CHANNEL_META,
            'status' => Campaign::STATUS_ACTIVE,
            'spend_cents' => 150_000,
        ]);

        $prospect = Prospect::create([
            'name' => 'Artista Teste',
            'whatsapp_e164' => '+5511999999999',
            'whatsapp_raw' => '(11) 99999-9999',
            'instagram' => 'artistateste',
            'source' => 'calculadora',
            'status' => Prospect::STATUS_BACKLOG,
            'revenue_monthly_cents' => 1_000_000, // R$10k
            'ticket_cents' => 150_000,            // R$1.5k
            'sessions_monthly' => 10,
            'admin_hours_day' => 2,
            'owner_id' => $owner->id,
            'campaign_id' => $campaign->id,
        ]);

        $this->assertSame(Prospect::BAND_ENTRY, $prospect->revenue_band);
        $this->assertGreaterThanOrEqual(0, $prospect->score);
        $this->assertLessThanOrEqual(100, $prospect->score);
        $this->assertTrue($prospect->isBacklog());
        $this->assertTrue($prospect->owner->is($owner));
        $this->assertTrue($prospect->campaign->is($campaign));
        $this->assertSame('(11) 99999-9999', $prospect->displayPhone());
    }

    public function test_activity_and_contract_relationships(): void
    {
        $prospect = Prospect::create([
            'name' => 'Artista Contrato',
            'whatsapp_e164' => '+5511888888888',
            'status' => Prospect::STATUS_BACKLOG,
        ]);

        $activity = $prospect->activities()->create([
            'type' => 'note',
            'title' => 'Primeiro contato',
            'body' => 'Retornar amanhã.',
        ]);

        $contract = $prospect->contracts()->create([
            'plan' => 'semestral',
            'monthly_value_cents' => 180_000,
            'total_value_cents' => 990_000,
            'start_date' => '2026-11-10',
            'status' => 'active',
            'signed_at' => now(),
        ]);

        $this->assertTrue($activity->prospect->is($prospect));
        $this->assertTrue($contract->prospect->is($prospect));
        $this->assertFalse($contract->isMembersArea());
    }
}
