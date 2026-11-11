<?php

namespace Tests\Feature;

use App\Models\BillingExpense;
use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotionFinanceImportTest extends TestCase
{
    use RefreshDatabase;

    private string $fixture;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixture = base_path('tests/Fixtures/notion-finance.json');

        User::create([
            'name' => 'Daniel',
            'email' => 'daniel@vaif.test',
            'password' => 'password-123',
            'role' => User::ROLE_STAFF,
        ]);

        Prospect::create([
            'name' => 'Cliente Pago',
            'whatsapp_e164' => '+5511900000001',
            'email' => 'pago@example.com',
            'status' => Prospect::STATUS_BACKLOG,
            'source' => 'notion',
        ]);

        Prospect::create([
            'name' => 'Cliente Não Pago',
            'whatsapp_e164' => '+5511900000002',
            'email' => 'nao-pago@example.com',
            'status' => Prospect::STATUS_BACKLOG,
            'source' => 'notion',
        ]);

        Prospect::create([
            'name' => 'Cliente Instagram Antigo',
            'whatsapp_e164' => '+5511900000004',
            'instagram' => 'instagrammatch',
            'status' => Prospect::STATUS_BACKLOG,
            'source' => 'notion',
        ]);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->artisan('finance:import-notion', [
            'file' => $this->fixture,
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertSame(0, BillingInvoice::count());
        $this->assertSame(0, BillingPayment::count());
        $this->assertSame(0, BillingExpense::count());
        $this->assertSame(3, Prospect::count());
    }

    public function test_import_creates_invoices_payments_and_expenses(): void
    {
        $this->artisan('finance:import-notion', ['file' => $this->fixture])
            ->assertSuccessful();

        $this->assertSame(4, BillingInvoice::count());
        $this->assertSame(2, BillingPayment::count());
        $this->assertSame(2, BillingExpense::count());
        $this->assertSame(4, Prospect::count());

        $paid = BillingInvoice::where('external_ref', 'notion:entrada:entrada-paid')->firstOrFail();
        $this->assertSame(100_000, $paid->amount_cents);
        $this->assertSame(BillingInvoice::STATUS_PAID, $paid->status);
        $this->assertSame(100_000, $paid->paid_cents);
        $this->assertSame(BillingInvoice::METHOD_PIX, $paid->payment_method);
        $this->assertSame('Cliente Pago', $paid->prospect->name);

        $unpaid = BillingInvoice::where('external_ref', 'notion:entrada:entrada-unpaid')->firstOrFail();
        $this->assertSame(50_000, $unpaid->amount_cents);
        $this->assertSame(BillingInvoice::METHOD_CREDIT_CARD, $unpaid->payment_method);
        $this->assertNotSame(BillingInvoice::STATUS_PAID, $unpaid->status);
        $this->assertSame(0, $unpaid->paid_cents);

        $instagram = BillingInvoice::where('external_ref', 'notion:entrada:entrada-instagram')->firstOrFail();
        $this->assertSame('Cliente Instagram Antigo', $instagram->prospect->name);
        $this->assertSame(BillingInvoice::METHOD_OTHER, $instagram->payment_method);

        $this->assertSame(2, BillingPayment::where('source', BillingPayment::SOURCE_NOTION)->count());
        $this->assertDatabaseHas('billing_payments', [
            'external_ref' => 'notion:entrada:entrada-paid:payment',
            'source' => 'notion',
            'amount_cents' => 100_000,
        ]);

        $placeholder = Prospect::where('name', 'Cliente Sem Contato')->firstOrFail();
        $this->assertSame(Prospect::STATUS_ARCHIVED, $placeholder->status);
        $this->assertStringStartsWith('+550000000', $placeholder->whatsapp_e164);
        $this->assertContains('sem_telefone_notion', $placeholder->import_flags);

        $canva = BillingExpense::where('notion_page_id', 'saida-canva')->firstOrFail();
        $this->assertSame('Canva', $canva->description);
        $this->assertSame(5_000, $canva->amount_cents);
        $this->assertSame(BillingExpense::CATEGORY_SOFTWARE, $canva->category);
        $this->assertSame(BillingExpense::STATUS_PAID, $canva->status);
        $this->assertSame(now()->startOfMonth()->toDateString(), $canva->competence_month->toDateString());
        $this->assertSame(1, $canva->created_by);

        $facebook = BillingExpense::where('notion_page_id', 'saida-facebook')->firstOrFail();
        $this->assertSame(BillingExpense::CATEGORY_ADS, $facebook->category);
        $this->assertSame(20_000, $facebook->amount_cents);
        $this->assertSame(now()->startOfMonth()->endOfMonth()->toDateString(), $facebook->due_date->toDateString());

        $this->assertDatabaseMissing('billing_expenses', ['notion_page_id' => 'saida-zero']);
        $this->assertDatabaseMissing('billing_invoices', ['external_ref' => 'notion:entrada:entrada-zero']);
    }

    public function test_reimport_is_idempotent(): void
    {
        $this->artisan('finance:import-notion', ['file' => $this->fixture])->assertSuccessful();
        $this->artisan('finance:import-notion', ['file' => $this->fixture])->assertSuccessful();

        $this->assertSame(4, BillingInvoice::count());
        $this->assertSame(2, BillingPayment::count());
        $this->assertSame(2, BillingExpense::count());
        $this->assertSame(4, Prospect::count());
        $this->assertSame(100_000, BillingInvoice::where('external_ref', 'notion:entrada:entrada-paid')->firstOrFail()->paid_cents);
    }

    public function test_report_file_lists_skipped_zero_amount_rows(): void
    {
        $report = sys_get_temp_dir().'/notion-finance-report.json';
        @unlink($report);

        $this->artisan('finance:import-notion', [
            'file' => $this->fixture,
            '--dry-run' => true,
            '--report' => $report,
        ])->assertSuccessful();

        $this->assertFileExists($report);

        $payload = json_decode((string) file_get_contents($report), true);
        $this->assertSame(2, $payload['stats']['skipped_no_amount']);
        $this->assertCount(2, $payload['skipped']);

        @unlink($report);
    }
}
