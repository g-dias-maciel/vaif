<?php

namespace Tests\Feature;

use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\BillingSetting;
use App\Models\Prospect;
use App\Models\ProspectActivity;
use App\Models\ProspectContract;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $sales;

    private Prospect $prospect;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staff = User::create([
            'name' => 'Staff Finance',
            'email' => 'staff@vaif.test',
            'password' => 'password-123',
            'role' => User::ROLE_STAFF,
        ]);

        $this->sales = User::create([
            'name' => 'Sales Rep',
            'email' => 'sales@vaif.test',
            'password' => 'password-123',
            'role' => User::ROLE_SALES,
        ]);

        $this->prospect = Prospect::create([
            'name' => 'Cliente Financeiro',
            'whatsapp_e164' => '+5511988887777',
            'instagram' => 'cliente.financeiro',
            'email' => 'cliente@example.com',
            'source' => 'notion',
            'status' => Prospect::STATUS_ACTIVE,
            'stage_key' => 'ganho',
            'handoff_at' => now(),
        ]);
    }

    public function test_pix_contract_generates_one_invoice_and_advances_the_billing_date(): void
    {
        $contract = $this->contract();

        $this->artisan('finance:generate-invoices', ['--date' => now()->toDateString()])
            ->assertSuccessful();

        $invoice = BillingInvoice::firstOrFail();

        $this->assertSame(1, BillingInvoice::count());
        $this->assertMatchesRegularExpression('/^VAIF-\d{4}-0001$/', $invoice->number);
        $this->assertSame($contract->id, $invoice->contract_id);
        $this->assertSame($this->prospect->id, $invoice->prospect_id);
        $this->assertSame(180_000, $invoice->amount_cents);
        $this->assertSame(BillingInvoice::STATUS_SENT, $invoice->status);
        $this->assertSame(now()->toDateString(), $invoice->period_start->toDateString());
        $this->assertSame(1, $invoice->items()->count());
        $this->assertSame(0, $invoice->paid_cents);

        $expectedNext = now()->startOfDay()->addMonthNoOverflow()->toDateString();
        $this->assertSame($expectedNext, $contract->fresh()->next_invoice_at->toDateString());

        $this->artisan('finance:generate-invoices', ['--date' => now()->toDateString()])
            ->assertSuccessful();

        $this->assertSame(1, BillingInvoice::count());
    }

    public function test_card_contract_never_generates_an_invoice_and_records_a_payment(): void
    {
        $contract = $this->contract([
            'billing_mode' => 'card',
            'payment_method' => 'credit_card',
            'next_invoice_at' => null,
            'next_charge_at' => now()->toDateString(),
        ]);

        $this->artisan('finance:generate-invoices', ['--date' => now()->toDateString()])
            ->assertSuccessful();

        $this->assertSame(0, BillingInvoice::count());

        $this->actingAs($this->staff)
            ->post(route('finance.payments.store'), [
                'contract_id' => $contract->id,
                'prospect_id' => $this->prospect->id,
                'amount' => 1800,
                'paid_at' => now()->toDateString(),
                'method' => 'credit_card',
                'source' => 'manual',
                'reference' => 'MP-123',
            ])
            ->assertRedirect(route('finance.payments.index'));

        $payment = BillingPayment::firstOrFail();

        $this->assertNull($payment->invoice_id);
        $this->assertSame($contract->id, $payment->contract_id);
        $this->assertSame(180_000, $payment->amount_cents);
        $this->assertSame('credit_card', $payment->method);

        $this->assertSame(
            now()->startOfDay()->addMonthNoOverflow()->toDateString(),
            $contract->fresh()->next_charge_at->toDateString(),
        );
    }

    public function test_partial_payments_keep_the_invoice_open_until_the_balance_is_paid(): void
    {
        $invoice = $this->invoice([
            'amount_cents' => 100_000,
            'due_date' => now()->toDateString(),
        ]);

        $this->actingAs($this->staff)
            ->post(route('finance.payments.store'), [
                'invoice_id' => $invoice->id,
                'amount' => 400,
                'paid_at' => now()->toDateString(),
                'method' => 'pix',
            ])
            ->assertRedirect(route('finance.invoices.show', $invoice));

        $invoice->refresh();

        $this->assertSame(40_000, $invoice->paid_cents);
        $this->assertSame(BillingInvoice::STATUS_SENT, $invoice->status);
        $this->assertSame(60_000, $invoice->balanceCents());

        $this->actingAs($this->staff)
            ->post(route('finance.payments.store'), [
                'invoice_id' => $invoice->id,
                'amount' => 600,
                'paid_at' => now()->toDateString(),
                'method' => 'pix',
            ])
            ->assertRedirect(route('finance.invoices.show', $invoice));

        $invoice->refresh();

        $this->assertSame(100_000, $invoice->paid_cents);
        $this->assertSame(BillingInvoice::STATUS_PAID, $invoice->status);
        $this->assertSame(0, $invoice->balanceCents());
        $this->assertSame(2, $invoice->payments()->count());
    }

    public function test_overdue_command_marks_unpaid_invoices_and_payment_closes_them(): void
    {
        $invoice = $this->invoice([
            'amount_cents' => 50_000,
            'due_date' => now()->subDay()->toDateString(),
            'status' => BillingInvoice::STATUS_SENT,
        ]);

        $this->artisan('finance:mark-overdue', ['--date' => now()->toDateString()])
            ->assertSuccessful();

        $this->assertSame(BillingInvoice::STATUS_OVERDUE, $invoice->fresh()->status);

        $this->actingAs($this->staff)
            ->post(route('finance.payments.store'), [
                'invoice_id' => $invoice->id,
                'amount' => 500,
                'paid_at' => now()->toDateString(),
                'method' => 'pix',
            ])
            ->assertRedirect();

        $this->assertSame(BillingInvoice::STATUS_PAID, $invoice->fresh()->status);
    }

    public function test_finance_routes_are_staff_only(): void
    {
        $this->actingAs($this->staff)
            ->get(route('finance.dashboard'))
            ->assertOk();

        $this->actingAs($this->sales)
            ->get(route('finance.dashboard'))
            ->assertForbidden();

        $this->actingAs($this->sales)
            ->get(route('finance.invoices.index'))
            ->assertForbidden();

        $this->actingAs($this->sales)
            ->get(route('finance.clients'))
            ->assertForbidden();
    }

    public function test_clients_page_eager_loads_the_latest_active_contract(): void
    {
        $this->contract([
            'start_date' => now()->subYear()->toDateString(),
            'next_invoice_at' => now()->addMonth()->toDateString(),
        ]);

        $latest = $this->contract([
            'start_date' => now()->subDay()->toDateString(),
            'next_invoice_at' => now()->addMonth()->toDateString(),
        ]);

        $this->actingAs($this->staff)
            ->get(route('finance.clients'))
            ->assertOk()
            ->assertSee($this->prospect->name);

        $loaded = Prospect::with('activeContract')->findOrFail($this->prospect->id);

        $this->assertNotNull($loaded->activeContract);
        $this->assertSame($latest->id, $loaded->activeContract->id);
    }

    public function test_staff_can_link_a_contract_without_creating_a_login(): void
    {
        $this->actingAs($this->staff)
            ->post(route('finance.contracts.store', $this->prospect), [
                'plan' => 'semestral',
                'monthly_value' => 1800,
                'total_value' => 10800,
                'start_date' => now()->toDateString(),
                'billing_day' => 10,
                'billing_mode' => 'pix',
                'payment_method' => 'pix',
                'status' => 'active',
                'signed_at' => now()->toDateString(),
            ])
            ->assertRedirect(route('finance.clients'));

        $contract = $this->prospect->contracts()->firstOrFail();

        $this->assertSame(180_000, $contract->monthly_value_cents);
        $this->assertSame(1_080_000, $contract->total_value_cents);
        $this->assertSame('pix', $contract->billing_mode);
        $this->assertSame(now()->toDateString(), $contract->next_invoice_at->toDateString());
        $this->assertSame(0, User::where('role', User::ROLE_ARTIST)->count());

        $this->assertDatabaseHas('prospect_activities', [
            'prospect_id' => $this->prospect->id,
            'type' => ProspectActivity::TYPE_CONTRACT,
            'title' => 'Contrato criado',
        ]);

        $this->actingAs($this->staff)
            ->post(route('finance.contracts.store', $this->prospect), [
                'plan' => 'mensal',
                'monthly_value' => 3250,
                'start_date' => now()->toDateString(),
                'billing_day' => 10,
                'billing_mode' => 'pix',
                'payment_method' => 'pix',
                'status' => 'active',
            ])
            ->assertSessionHasErrors('status');
    }

    public function test_staff_can_create_a_manual_invoice_and_open_its_receipt(): void
    {
        $this->actingAs($this->staff)
            ->post(route('finance.invoices.store'), [
                'prospect_id' => $this->prospect->id,
                'amount' => 2500,
                'period_start' => now()->toDateString(),
                'period_end' => now()->addMonthNoOverflow()->subDay()->toDateString(),
                'issue_date' => now()->toDateString(),
                'due_date' => now()->addDays(5)->toDateString(),
                'status' => 'sent',
                'payment_method' => 'pix',
                'description' => 'Setup + primeiro mês',
            ])
            ->assertRedirect();

        $invoice = BillingInvoice::firstOrFail();

        $this->assertSame(250_000, $invoice->amount_cents);
        $this->assertSame(1, $invoice->items()->count());

        $this->actingAs($this->staff)
            ->get(route('finance.invoices.show', $invoice))
            ->assertOk()
            ->assertSee($invoice->number);

        $this->actingAs($this->staff)
            ->get(route('finance.invoices.receipt', $invoice))
            ->assertOk()
            ->assertSee($invoice->number)
            ->assertSee('Recibo');
    }

    public function test_staff_can_update_the_finance_settings(): void
    {
        $this->actingAs($this->staff)
            ->put(route('finance.settings.update'), [
                'legal_name' => 'VAIF Marketing LTDA',
                'tax_id' => '12.345.678/0001-90',
                'invoice_prefix' => 'VAIF',
                'default_due_day' => 10,
                'pix_key' => 'financeiro@vaif.com.br',
                'payment_instructions' => 'Pague via PIX e envie o comprovante.',
                'receipt_footer' => 'Obrigado.',
            ])
            ->assertRedirect(route('finance.settings'));

        $this->assertSame('VAIF Marketing LTDA', BillingSetting::current()->legal_name);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function contract(array $overrides = []): ProspectContract
    {
        return $this->prospect->contracts()->create(array_merge([
            'plan' => ProspectContract::PLAN_SEMESTRAL,
            'monthly_value_cents' => 180_000,
            'total_value_cents' => 1_080_000,
            'start_date' => now()->toDateString(),
            'status' => ProspectContract::STATUS_ACTIVE,
            'billing_day' => 10,
            'billing_mode' => 'pix',
            'payment_method' => 'pix',
            'next_invoice_at' => now()->toDateString(),
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function invoice(array $overrides = []): BillingInvoice
    {
        $invoice = BillingInvoice::create(array_merge([
            'number' => 'VAIF-2026-9001',
            'prospect_id' => $this->prospect->id,
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'issue_date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'amount_cents' => 180_000,
            'paid_cents' => 0,
            'status' => BillingInvoice::STATUS_SENT,
            'payment_method' => 'pix',
        ], $overrides));

        $invoice->items()->create([
            'description' => 'Plano semestral',
            'quantity' => 1,
            'unit_amount_cents' => $invoice->amount_cents,
            'total_cents' => $invoice->amount_cents,
            'sort_order' => 0,
        ]);

        return $invoice;
    }
}
