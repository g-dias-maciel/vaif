<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesIngestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['crm.sales_ingest_token' => 'test-ingest-token']);
    }

    public function test_valid_payload_creates_an_active_prospect(): void
    {
        $campaign = Campaign::create([
            'name' => 'Meta Coorte',
            'channel' => Campaign::CHANNEL_META,
            'status' => Campaign::STATUS_ACTIVE,
            'utm_campaign' => 'coorte',
        ]);

        $this->postIngest($this->payload())
            ->assertCreated()
            ->assertJson(['ok' => true, 'duplicate' => false]);

        $prospect = Prospect::where('whatsapp_e164', '+5511977776666')->firstOrFail();

        $this->assertTrue($prospect->isActive());
        $this->assertSame('novo', $prospect->stage_key);
        $this->assertSame(1_200_000, $prospect->revenue_monthly_cents);
        $this->assertSame(Prospect::BAND_ENTRY, $prospect->revenue_band);
        $this->assertSame('meta', $prospect->utm_source);
        $this->assertSame('cpc', $prospect->utm_medium);
        $this->assertSame('coorte', $prospect->utm_campaign);
        $this->assertSame($campaign->id, $prospect->campaign_id);
        $this->assertNotNull($prospect->next_action_at);

        $this->assertDatabaseHas('prospect_activities', [
            'prospect_id' => $prospect->id,
            'type' => 'import',
            'title' => 'Lead recebido da calculadora',
        ]);
    }

    public function test_duplicate_updates_fields_and_preserves_sales_state(): void
    {
        $owner = User::create([
            'name' => 'Sales Rep',
            'email' => 'sales@vaif.test',
            'password' => 'password-123',
            'role' => User::ROLE_SALES,
        ]);

        $prospect = Prospect::create([
            'name' => 'Artista Original',
            'whatsapp_e164' => '+5511977776666',
            'status' => Prospect::STATUS_ACTIVE,
            'stage_key' => 'proposta',
            'revenue_monthly_cents' => 1_000_000,
            'owner_id' => $owner->id,
            'notes' => 'manter esta nota',
        ]);

        $this->postIngest(array_merge($this->payload(), [
            'nome' => 'Artista Atualizado',
            'faturamento' => 20000,
            'ticket' => 2000,
        ]))
            ->assertOk()
            ->assertJson(['ok' => true, 'duplicate' => true]);

        $prospect->refresh();

        $this->assertSame('Artista Atualizado', $prospect->name);
        $this->assertSame(2_000_000, $prospect->revenue_monthly_cents);
        $this->assertSame('proposta', $prospect->stage_key);
        $this->assertSame($owner->id, $prospect->owner_id);
        $this->assertSame('manter esta nota', $prospect->notes);
        $this->assertSame(1, Prospect::count());
        $this->assertSame(1, $prospect->activities()->count());
    }

    public function test_invalid_token_is_rejected(): void
    {
        $this->postJson('/api/sales/prospects', $this->payload())
            ->assertUnauthorized();

        $this->withHeader('Authorization', 'Bearer wrong-token')
            ->postJson('/api/sales/prospects', $this->payload())
            ->assertUnauthorized();

        $this->assertSame(0, Prospect::count());
    }

    public function test_invalid_phone_is_rejected(): void
    {
        $this->postIngest(array_merge($this->payload(), ['whatsapp' => '123']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('whatsapp_e164');

        $this->assertSame(0, Prospect::count());
    }

    public function test_missing_token_configuration_is_a_503(): void
    {
        config(['crm.sales_ingest_token' => null]);

        $this->postIngest($this->payload())->assertStatus(503);
    }

    public function test_origin_defaults_to_calculadora(): void
    {
        $payload = $this->payload();
        unset($payload['origem']);

        $this->postIngest($payload)->assertCreated();

        $this->assertSame(
            'calculadora',
            Prospect::where('whatsapp_e164', '+5511977776666')->firstOrFail()->source,
        );
    }

    public function test_low_revenue_is_corrected_and_flagged(): void
    {
        $this->postIngest(array_merge($this->payload(), [
            'faturamento' => 8,
            'ticket' => 750,
        ]))->assertCreated();

        $prospect = Prospect::where('whatsapp_e164', '+5511977776666')->firstOrFail();

        $this->assertSame(800_000, $prospect->revenue_monthly_cents);
        $this->assertContains('receita_corrigida', $prospect->import_flags);
        $this->assertContains('receita_suspeita_baixa', $prospect->import_flags);
    }

    public function test_invalid_sessions_are_nulled_and_flagged(): void
    {
        $this->postIngest(array_merge($this->payload(), [
            'sessoes' => -10,
        ]))->assertCreated();

        $prospect = Prospect::where('whatsapp_e164', '+5511977776666')->firstOrFail();

        $this->assertNull($prospect->sessions_monthly);
        $this->assertContains('sessoes_outlier', $prospect->import_flags);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'nome' => 'Artista Ingest',
            'whatsapp' => '(11) 97777-6666',
            'instagram' => 'artistaingest',
            'faturamento' => 12000,
            'ticket' => 1200,
            'sessoes' => 10,
            'horas_admin' => 2,
            'prejuizo_mensal' => 5000,
            'potencial_lucro' => 15000,
            'origem' => 'calculadora',
            'utm_source' => 'meta',
            'utm_medium' => 'cpc',
            'utm_campaign' => 'coorte',
            'utm_content' => 'video-1',
            'utm_term' => 'tatuador',
        ];
    }

    private function postIngest(array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer test-ingest-token')
            ->postJson('/api/sales/prospects', $payload);
    }
}
