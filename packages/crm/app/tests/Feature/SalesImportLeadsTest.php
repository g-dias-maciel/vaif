<?php

namespace Tests\Feature;

use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesImportLeadsTest extends TestCase
{
    use RefreshDatabase;

    private string $dumpPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dumpPath = storage_path('app/test-sales-leads.sql');

        $dump = <<<'SQL'
-- phpMyAdmin SQL Dump (fixture)
INSERT INTO `leads` (`id`, `nome`, `whatsapp`, `instagram`, `faturamento`, `ticket`, `sessoes`, `horas_admin`, `valor_hora`, `horas_secretario`, `prejuizo_mensal`, `potencial_lucro`, `processado`, `notion_id`, `created_at`, `data_agendamento`) VALUES
(1, 'Artista A', '(11) 99999-0001', 'artistaa', 10000.00, 1500.00, 10, 2.00, 100.00, 52.00, 5000.00, 15000.00, 1, 'n1', '2026-06-01 10:00:00', NULL),
(2, 'Teste', '(11) 11111-1111', 'teste', 12000.00, 1200.00, 12, 2.00, 125.00, 52.00, 6500.00, 16550.00, 1, 'n2', '2026-06-01 11:00:00', NULL),
(3, 'Artista C', '(11) 99999-0003', 'artistac', 8.00, 750.00, 10, 2.00, 63.00, 26.00, 1625.00, 3638.00, 1, 'n3', '2026-06-02 10:00:00', NULL),
(4, 'Artista A atualizado', '(11) 99999-0001', 'artistaa', 12000.00, 1500.00, 10, 2.00, 100.00, 52.00, 5000.00, 15000.00, 1, 'n1b', '2026-06-02 12:00:00', NULL),
(5, 'Artista D', '(11) 99999-0005', 'artistad', 15000.00, 1500.00, -10, 2.00, 100.00, 52.00, 5000.00, 15000.00, 1, NULL, '2026-06-03 10:00:00', '2026-06-04 14:00:00');
SQL;

        file_put_contents($this->dumpPath, $dump);
    }

    protected function tearDown(): void
    {
        @unlink($this->dumpPath);

        parent::tearDown();
    }

    public function test_import_creates_backlog_and_cleans_the_data(): void
    {
        $this->artisan('sales:import-leads', ['--file' => $this->dumpPath])
            ->assertExitCode(0);

        $this->assertDatabaseCount('prospects', 3);
        $this->assertDatabaseMissing('prospects', ['name' => 'Teste']);

        $a = Prospect::where('whatsapp_e164', '+5511999990001')->firstOrFail();
        $this->assertSame('Artista A atualizado', $a->name);
        $this->assertSame(1_200_000, $a->revenue_monthly_cents);
        $this->assertSame(Prospect::BAND_ENTRY, $a->revenue_band);
        $this->assertSame(Prospect::STATUS_BACKLOG, $a->status);
        $this->assertSame('calculadora', $a->source);
        $this->assertSame('2026-06-02 12:00:00', $a->created_at?->format('Y-m-d H:i:s'));

        $c = Prospect::where('whatsapp_e164', '+5511999990003')->firstOrFail();
        $this->assertSame(800_000, $c->revenue_monthly_cents);
        $this->assertContains('receita_corrigida', $c->import_flags);
        $this->assertContains('receita_suspeita_baixa', $c->import_flags);

        $d = Prospect::where('whatsapp_e164', '+5511999990005')->firstOrFail();
        $this->assertSame(Prospect::BAND_RETAINER, $d->revenue_band);
        $this->assertNull($d->sessions_monthly);
        $this->assertContains('sessoes_outlier', $d->import_flags);
        $this->assertNotNull($d->booked_call_at);
    }

    public function test_dry_run_does_not_write(): void
    {
        $this->artisan('sales:import-leads', [
            '--file' => $this->dumpPath,
            '--dry-run' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseCount('prospects', 0);
    }

    public function test_import_is_idempotent(): void
    {
        $this->artisan('sales:import-leads', ['--file' => $this->dumpPath])->assertExitCode(0);
        $this->artisan('sales:import-leads', ['--file' => $this->dumpPath])->assertExitCode(0);

        $this->assertDatabaseCount('prospects', 3);
    }

    public function test_owner_option_assigns_a_sales_user(): void
    {
        $sales = User::create([
            'name' => 'Sales Rep',
            'email' => 'sales@vaif.test',
            'password' => 'password',
            'role' => User::ROLE_SALES,
        ]);

        $this->artisan('sales:import-leads', [
            '--file' => $this->dumpPath,
            '--owner' => $sales->email,
        ])->assertExitCode(0);

        $this->assertSame(
            3,
            Prospect::where('owner_id', $sales->id)->count(),
        );
    }
}
