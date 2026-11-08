<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Prospect;
use App\Models\ProspectActivity;
use App\Models\ProspectContract;
use App\Models\User;
use App\Services\Sales\NotionPipelineImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotionImportTest extends TestCase
{
    use RefreshDatabase;

    private string $csv;

    protected function setUp(): void
    {
        parent::setUp();

        $this->csv = base_path('tests/Fixtures/notion-pipeline.csv');
    }

    public function test_import_routes_active_clients_and_backlog_without_contracts_or_logins(): void
    {
        $sales = User::create([
            'name' => 'Sales Rep',
            'email' => 'sales@vaif.test',
            'password' => 'password-123',
            'role' => User::ROLE_SALES,
        ]);

        $suspendedArtist = Artist::create([
            'name' => 'Cliente Suspenso',
            'status' => 'suspended',
            'timezone' => 'America/Sao_Paulo',
            'wa_session_slug' => 'cliente-suspenso',
        ]);
        $suspendedArtist->settings()->create([
            'display_name' => 'Cliente Suspenso',
            'whatsapp_number' => '5511900000008',
        ]);

        $existing = Prospect::create([
            'name' => 'Cliente Promovido',
            'whatsapp_e164' => '+5511900000009',
            'status' => Prospect::STATUS_BACKLOG,
            'source' => 'calculadora',
            'owner_id' => $sales->id,
            'notes' => 'nota preservada',
        ]);

        $userCount = User::count();
        $contractCount = ProspectContract::count();

        $this->artisan('sales:import-notion', ['file' => $this->csv, '--dry-run' => true])
            ->assertSuccessful();

        $this->assertSame(1, Prospect::count());
        $this->assertSame(1, Artist::count());

        $this->artisan('sales:import-notion', ['file' => $this->csv])
            ->assertSuccessful();

        $this->assertSame(7, Prospect::count());
        $this->assertSame(3, Artist::count());
        $this->assertSame($userCount, User::count());
        $this->assertSame($contractCount, ProspectContract::count());

        $ana = Prospect::where('whatsapp_e164', '+5511900000001')->firstOrFail();

        $this->assertTrue($ana->isActive());
        $this->assertSame('ganho', $ana->stage_key);
        $this->assertNotNull($ana->artist_id);
        $this->assertNotNull($ana->handoff_at);
        $this->assertNull($ana->next_action_at);

        $anaArtist = Artist::findOrFail($ana->artist_id);
        $this->assertSame('onboarding', $anaArtist->status);
        $this->assertSame('Ana Cliente', $anaArtist->name);
        $this->assertSame('Ana Cliente', $anaArtist->settings->display_name);
        $this->assertSame('5511900000001', $anaArtist->settings->whatsapp_number);

        $beto = Prospect::where('whatsapp_e164', '+5511900000002')->firstOrFail();

        $this->assertTrue($beto->isBacklog());
        $this->assertSame('Remarketing', $beto->legacy_status);
        $this->assertSame('beto@example.com', $beto->email);
        $this->assertContains(NotionPipelineImporter::FLAG_NOTION_BACKLOG, $beto->import_flags);
        $this->assertContains(NotionPipelineImporter::FLAG_DUPLICADO, $beto->import_flags);

        $paused = Prospect::where('whatsapp_e164', '+5511900000005')->firstOrFail();
        $this->assertTrue($paused->isBacklog());
        $this->assertContains(NotionPipelineImporter::FLAG_CLIENTE_SEM_ATIVO, $paused->import_flags);
        $this->assertContains(NotionPipelineImporter::FLAG_CLIENTE_PAUSADO, $paused->import_flags);

        $semNome = Prospect::where('whatsapp_e164', '+5511900000006')->firstOrFail();
        $this->assertSame('sem.nome', $semNome->name);
        $this->assertContains(NotionPipelineImporter::FLAG_SEM_NOME, $semNome->import_flags);

        $this->assertNotNull(Prospect::where('whatsapp_e164', '+5511900000007')->firstOrFail());
        $this->assertNull(Prospect::where('whatsapp_e164', '+5511900000004')->first());

        $suspended = Prospect::where('whatsapp_e164', '+5511900000008')->firstOrFail();
        $this->assertTrue($suspended->isActive());
        $this->assertSame($suspendedArtist->id, $suspended->artist_id);
        $this->assertContains(NotionPipelineImporter::FLAG_ARTISTA_SUSPENSO, $suspended->import_flags);
        $this->assertSame('suspended', $suspendedArtist->fresh()->status);

        $promoted = $existing->fresh();
        $this->assertTrue($promoted->isActive());
        $this->assertSame('ganho', $promoted->stage_key);
        $this->assertSame($sales->id, $promoted->owner_id);
        $this->assertSame('nota preservada', $promoted->notes);
        $this->assertNotNull($promoted->artist_id);
        $this->assertContains(NotionPipelineImporter::FLAG_PROMOVIDO, $promoted->import_flags);

        $activityCount = ProspectActivity::where('type', ProspectActivity::TYPE_IMPORT)->count();
        $artistCount = Artist::count();
        $prospectCount = Prospect::count();

        $this->artisan('sales:import-notion', ['file' => $this->csv])
            ->assertSuccessful();

        $this->assertSame($prospectCount, Prospect::count());
        $this->assertSame($artistCount, Artist::count());
        $this->assertSame($activityCount, ProspectActivity::where('type', ProspectActivity::TYPE_IMPORT)->count());
    }

    public function test_endpoint_imports_a_batch_and_uses_the_notion_page_id_for_idempotency(): void
    {
        config(['crm.notion_sync_token' => 'notion-token']);

        $page = [
            'notion_page_id' => 'page-0001',
            'name' => 'Cliente Endpoint',
            'whatsapp' => '(11) 90000-0010',
            'instagram' => 'https://instagram.com/cliente.endpoint',
            'email' => 'endpoint@example.com',
            'status_contrato' => 'Ativo',
            'pipeline' => 'Cliente',
            'valor_fechamento' => 'R$1,800.00',
        ];

        $this->withHeader('Authorization', 'Bearer notion-token')
            ->postJson('/api/sales/notion-sync', ['pages' => [
                $page,
                [
                    'notion_page_id' => 'page-0002',
                    'name' => 'Lead Endpoint',
                    'whatsapp' => '(11) 90000-0011',
                    'pipeline' => 'Contato Iniciado',
                ],
                [
                    'notion_page_id' => 'page-0003',
                    'name' => 'Sem Telefone Endpoint',
                    'whatsapp' => '+',
                ],
            ]])
            ->assertOk()
            ->assertJsonPath('stats.processed', 3)
            ->assertJsonPath('stats.created_client', 1)
            ->assertJsonPath('stats.created_backlog', 1)
            ->assertJsonPath('stats.skipped', 1)
            ->assertJsonPath('ok', true);

        $this->assertSame(1, Prospect::where('legacy_notion_id', 'page-0001')->count());
        $this->assertSame(1, Artist::count());

        $this->withHeader('Authorization', 'Bearer notion-token')
            ->postJson('/api/sales/notion-sync', ['pages' => [[
                'notion_page_id' => 'page-0001',
                'name' => 'Cliente Endpoint Atualizado',
                'whatsapp' => '(11) 90000-0010',
                'status_contrato' => 'Ativo',
                'pipeline' => 'Cliente',
            ]]])
            ->assertOk()
            ->assertJsonPath('stats.updated_client', 1);

        $this->assertSame(1, Prospect::where('legacy_notion_id', 'page-0001')->count());
        $this->assertSame(1, Artist::count());
        $this->assertSame('Cliente Endpoint Atualizado', Prospect::where('legacy_notion_id', 'page-0001')->firstOrFail()->name);
    }

    public function test_endpoint_rejects_a_missing_or_wrong_token(): void
    {
        config(['crm.notion_sync_token' => 'notion-token']);

        $this->postJson('/api/sales/notion-sync', ['pages' => []])
            ->assertUnauthorized();

        $this->withHeader('Authorization', 'Bearer wrong')
            ->postJson('/api/sales/notion-sync', ['pages' => []])
            ->assertUnauthorized();
    }

    public function test_endpoint_is_503_when_the_token_is_not_configured(): void
    {
        config(['crm.notion_sync_token' => null]);

        $this->withHeader('Authorization', 'Bearer anything')
            ->postJson('/api/sales/notion-sync', ['pages' => []])
            ->assertStatus(503);
    }
}
