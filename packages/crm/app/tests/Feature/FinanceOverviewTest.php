<?php

namespace Tests\Feature;

use App\Mail\InvoiceApprovalDigest;
use App\Models\BillingExpense;
use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\BillingSetting;
use App\Models\FinanceDistribution;
use App\Models\FinancePartner;
use App\Models\Prospect;
use App\Models\ProspectContract;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class FinanceOverviewTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private Prospect $prospect;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staff = User::create([
            'name' => 'Finance Staff',
            'email' => 'finance@vaif.test',
            'password' => 'password-123',
            'role' => User::ROLE_STAFF,
        ]);

        $this->prospect = Prospect::create([
            'name' => 'Cliente Overview',
            'whatsapp_e164' => '+5511999990000',
            'billing_address' => 'Rua das Faturas, 10',
            'source' => 'notion',
            'status' => Prospect::STATUS_ACTIVE,
            'stage_key' => 'ganho',
            'handoff_at' => now(),
        ]);
    }

    public function test_overview_month_settings_and_invoice_form_render(): void
    {
        BillingPayment::create([
            'prospect_id' => $this->prospect->id,
            'amount_cents' => 100_000,
            'method' => BillingPayment::METHOD_PIX,
            'paid_at' => now()->toDateString(),
            'source' => BillingPayment::SOURCE_MANUAL,
        ]);

        BillingExpense::create([
            'description' => 'Despesa do overview',
            'category' => BillingExpense::CATEGORY_SOFTWARE,
            'amount_cents' => 20_000,
            'competence_month' => now()->startOfMonth()->toDateString(),
            'due_date' => now()->toDateString(),
            'paid_at' => now()->toDateString(),
            'status' => BillingExpense::STATUS_PAID,
        ]);

        $this->actingAs($this->staff);

        $this->get(route('finance.dashboard', ['year' => now()->year]))
            ->assertOk()
            ->assertSee('Visão anual')
            ->assertSee('Lucro líquido')
            ->assertSee('Guardado')
            ->assertSee('ROI')
            ->assertSee('R$ 1.000,00')
            ->assertSee('R$ 200,00')
            ->assertSee('R$ 800,00');

        $this->get(route('finance.months', ['month' => now()->format('Y-m')]))
            ->assertOk()
            ->assertSee('Entradas')
            ->assertSee('Saídas')
            ->assertSee('Distribuição');

        $this->get(route('finance.settings'))
            ->assertOk()
            ->assertSee('Sócios')
            ->assertSee('Antecedência da cobrança');

        $this->get(route('finance.invoices.create'))
            ->assertOk()
            ->assertSee('Itens da fatura');

        $this->get(route('finance.expenses.create', ['month' => now()->format('Y-m')]))
            ->assertOk()
            ->assertSee('Pago por');

        $this->get(route('finance.contracts.create', $this->prospect))
            ->assertOk()
            ->assertSee('Endereço de cobrança');

        $this->get(route('finance.invoices.edit', $this->draftInvoice()))
            ->assertOk()
            ->assertSee('Itens da fatura');
    }

    public function test_distribution_records_profit_and_reimbursement(): void
    {
        $daniel = FinancePartner::create(['name' => 'Daniel', 'share_bps' => 5000, 'active' => true]);
        $gabriel = FinancePartner::create(['name' => 'Gabriel', 'share_bps' => 5000, 'active' => true]);

        $expense = BillingExpense::create([
            'description' => 'Anúncio pago pelo Daniel',
            'category' => BillingExpense::CATEGORY_ADS,
            'amount_cents' => 20_000,
            'competence_month' => now()->startOfMonth()->toDateString(),
            'due_date' => now()->toDateString(),
            'paid_at' => now()->toDateString(),
            'status' => BillingExpense::STATUS_PAID,
            'paid_by_partner_id' => $daniel->id,
        ]);

        BillingPayment::create([
            'prospect_id' => $this->prospect->id,
            'amount_cents' => 100_000,
            'method' => BillingPayment::METHOD_PIX,
            'paid_at' => now()->toDateString(),
            'source' => BillingPayment::SOURCE_MANUAL,
        ]);

        $this->actingAs($this->staff)
            ->post(route('finance.months.distribution'), [
                'month' => now()->format('Y-m'),
                'distributions' => [
                    $daniel->id => ['profit' => 400, 'expense_ids' => [$expense->id]],
                    $gabriel->id => ['profit' => 400],
                ],
            ])
            ->assertRedirect(route('finance.months', ['month' => now()->format('Y-m')]));

        $this->assertDatabaseHas('finance_distributions', [
            'partner_id' => $daniel->id,
            'kind' => FinanceDistribution::KIND_PROFIT,
            'amount_cents' => 40_000,
        ]);
        $this->assertDatabaseHas('finance_distributions', [
            'partner_id' => $daniel->id,
            'kind' => FinanceDistribution::KIND_REIMBURSEMENT,
            'amount_cents' => 20_000,
        ]);
        $this->assertDatabaseHas('finance_distributions', [
            'partner_id' => $gabriel->id,
            'kind' => FinanceDistribution::KIND_PROFIT,
            'amount_cents' => 40_000,
        ]);

        $expense->refresh();
        $this->assertNotNull($expense->reimbursed_at);
        $this->assertNotNull($expense->reimbursed_distribution_id);

        $this->get(route('finance.months', ['month' => now()->format('Y-m')]))
            ->assertOk()
            ->assertSee('Reembolso');
    }

    public function test_invoice_approval_sends_whatsapp_and_marks_delivery(): void
    {
        Storage::fake('local');

        Http::fake([
            '*contacts/check-exists*' => Http::response(['numberExists' => true, 'chatId' => '5511999990000@c.us']),
            '*api/sendText*' => Http::response(['ok' => true]),
        ]);

        $invoice = $this->draftInvoice();

        $this->actingAs($this->staff)
            ->post(route('finance.invoices.approve', $invoice))
            ->assertRedirect(route('finance.invoices.show', $invoice));

        $invoice->refresh();

        $this->assertSame(BillingInvoice::STATUS_SENT, $invoice->status);
        $this->assertSame(BillingInvoice::DELIVERY_SENT, $invoice->delivery_status);
        $this->assertNotNull($invoice->approved_at);
        $this->assertSame($this->staff->id, $invoice->approved_by);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/sendText')
            && str_contains((string) $request['text'], 'Cliente')
            && str_contains((string) $request['text'], 'http'));
    }

    public function test_staff_can_edit_a_draft_invoice_with_multiple_items(): void
    {
        $invoice = $this->draftInvoice();

        $this->actingAs($this->staff)
            ->put(route('finance.invoices.update', $invoice), [
                'prospect_id' => $this->prospect->id,
                'period_start' => now()->startOfMonth()->toDateString(),
                'period_end' => now()->endOfMonth()->toDateString(),
                'issue_date' => now()->toDateString(),
                'due_date' => now()->addDays(10)->toDateString(),
                'payment_method' => 'pix',
                'discount' => 100,
                'items' => [
                    ['description' => 'Plano semestral', 'quantity' => 1, 'unit_amount' => 1800],
                    ['description' => 'Setup', 'quantity' => 1, 'unit_amount' => 250],
                ],
                'notes' => 'Dois itens',
            ])
            ->assertRedirect(route('finance.invoices.show', $invoice));

        $invoice->refresh();

        $this->assertSame(205_000, $invoice->subtotal_cents);
        $this->assertSame(10_000, $invoice->discount_cents);
        $this->assertSame(195_000, $invoice->amount_cents);
        $this->assertSame(2, $invoice->items()->count());
    }

    public function test_contract_generate_invoice_creates_a_draft_even_outside_the_lead_window(): void
    {
        $contract = $this->prospect->contracts()->create([
            'plan' => ProspectContract::PLAN_SEMESTRAL,
            'monthly_value_cents' => 180_000,
            'total_value_cents' => 1_080_000,
            'start_date' => now()->toDateString(),
            'status' => ProspectContract::STATUS_ACTIVE,
            'billing_day' => 10,
            'billing_mode' => 'pix',
            'payment_method' => 'pix',
            'next_invoice_at' => now()->addMonthsNoOverflow(2)->toDateString(),
        ]);

        $this->actingAs($this->staff)
            ->post(route('finance.contracts.generate-invoice', $contract))
            ->assertRedirect();

        $invoice = BillingInvoice::firstOrFail();

        $this->assertSame(BillingInvoice::STATUS_DRAFT, $invoice->status);
        $this->assertSame($contract->id, $invoice->contract_id);
        $this->assertSame(
            now()->addMonthsNoOverflow(3)->toDateString(),
            $contract->fresh()->next_invoice_at->toDateString(),
        );
    }

    public function test_mark_overdue_does_not_touch_drafts(): void
    {
        $invoice = $this->draftInvoice();
        $invoice->update(['due_date' => now()->subDay()->toDateString()]);

        $this->artisan('finance:mark-overdue', ['--date' => now()->toDateString()])
            ->assertSuccessful();

        $this->assertSame(BillingInvoice::STATUS_DRAFT, $invoice->fresh()->status);
    }

    public function test_invoice_pdf_and_public_signed_link(): void
    {
        Storage::fake('local');

        $invoice = $this->draftInvoice();

        $this->actingAs($this->staff)
            ->get(route('finance.invoices.pdf', $invoice))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $signed = URL::temporarySignedRoute(
            'finance.invoices.public-pdf',
            now()->addDay(),
            ['invoice' => $invoice->id],
        );

        $this->get($signed)
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->get(route('finance.invoices.public-pdf', $invoice))
            ->assertForbidden();
    }

    public function test_generation_command_marks_drafts_and_emails_approval_digest(): void
    {
        Mail::fake();

        $this->prospect->contracts()->create([
            'plan' => ProspectContract::PLAN_SEMESTRAL,
            'monthly_value_cents' => 180_000,
            'total_value_cents' => 1_080_000,
            'start_date' => now()->toDateString(),
            'status' => ProspectContract::STATUS_ACTIVE,
            'billing_day' => 10,
            'billing_mode' => 'pix',
            'payment_method' => 'pix',
            'next_invoice_at' => now()->toDateString(),
        ]);

        BillingSetting::current()->update([
            'invoice_approval_email' => 'financeiro@vaif.test',
            'invoice_lead_days' => 3,
        ]);

        $this->artisan('finance:generate-invoices', ['--date' => now()->toDateString()])
            ->assertSuccessful();

        $invoice = BillingInvoice::firstOrFail();

        $this->assertSame(BillingInvoice::STATUS_DRAFT, $invoice->status);
        $this->assertSame(BillingInvoice::DELIVERY_PENDING, $invoice->delivery_status);

        Mail::assertSent(InvoiceApprovalDigest::class);
    }

    public function test_partner_shares_cannot_exceed_one_hundred_percent(): void
    {
        FinancePartner::create(['name' => 'Daniel', 'share_bps' => 7000, 'active' => true]);

        $this->actingAs($this->staff)
            ->post(route('finance.partners.store'), [
                'name' => 'Gabriel',
                'share_percent' => 40,
                'active' => 1,
            ])
            ->assertSessionHasErrors('share_percent');
    }

    private function draftInvoice(): BillingInvoice
    {
        $invoice = BillingInvoice::create([
            'number' => 'INV-2026-8001',
            'prospect_id' => $this->prospect->id,
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(5)->toDateString(),
            'amount_cents' => 180_000,
            'paid_cents' => 0,
            'subtotal_cents' => 180_000,
            'discount_cents' => 0,
            'status' => BillingInvoice::STATUS_DRAFT,
            'payment_method' => BillingInvoice::METHOD_PIX,
            'delivery_status' => BillingInvoice::DELIVERY_PENDING,
            'notes' => 'Fatura de teste',
        ]);

        $invoice->items()->create([
            'description' => 'Plano semestral',
            'quantity' => 1,
            'unit_amount_cents' => 180_000,
            'total_cents' => 180_000,
            'sort_order' => 0,
        ]);

        return $invoice;
    }
}
