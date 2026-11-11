<?php

namespace Tests\Feature;

use App\Models\BillingExpense;
use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\Prospect;
use App\Models\ProspectContract;
use App\Models\User;
use App\Services\Finance\MonthlyFinanceReport;
use App\Services\Finance\RecurringExpenseGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceExpenseTest extends TestCase
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
            'email' => 'staff-expense@vaif.test',
            'password' => 'password-123',
            'role' => User::ROLE_STAFF,
        ]);

        $this->sales = User::create([
            'name' => 'Sales Rep',
            'email' => 'sales-expense@vaif.test',
            'password' => 'password-123',
            'role' => User::ROLE_SALES,
        ]);

        $this->prospect = Prospect::create([
            'name' => 'Cliente Despesas',
            'whatsapp_e164' => '+5511977776666',
            'source' => 'notion',
            'status' => Prospect::STATUS_ACTIVE,
            'stage_key' => 'ganho',
            'handoff_at' => now(),
        ]);
    }

    public function test_staff_can_create_an_expense_and_mark_it_paid(): void
    {
        $this->actingAs($this->staff)
            ->post(route('finance.expenses.store'), [
                'description' => 'Meta Ads',
                'vendor' => 'Meta',
                'category' => 'ads',
                'amount' => 2500,
                'competence_month' => now()->format('Y-m'),
                'due_date' => now()->addDays(5)->toDateString(),
                'status' => 'pending',
                'payment_method' => 'credit_card',
                'recurrence' => 'one_off',
                'notes' => 'Campanha de novembro',
            ])
            ->assertRedirect(route('finance.expenses.index', ['month' => now()->format('Y-m')]));

        $expense = BillingExpense::firstOrFail();

        $this->assertSame('Meta Ads', $expense->description);
        $this->assertSame(250_000, $expense->amount_cents);
        $this->assertSame(now()->startOfMonth()->toDateString(), $expense->competence_month->toDateString());
        $this->assertSame(BillingExpense::STATUS_PENDING, $expense->status);
        $this->assertSame($this->staff->id, $expense->created_by);

        $this->actingAs($this->staff)
            ->get(route('finance.expenses.index', ['month' => now()->format('Y-m')]))
            ->assertOk()
            ->assertSee('Meta Ads');

        $this->actingAs($this->staff)
            ->post(route('finance.expenses.paid', $expense))
            ->assertRedirect();

        $expense->refresh();

        $this->assertSame(BillingExpense::STATUS_PAID, $expense->status);
        $this->assertSame(now()->toDateString(), $expense->paid_at->toDateString());
    }

    public function test_monthly_report_combines_invoices_payments_contracts_and_expenses(): void
    {
        $contract = $this->prospect->contracts()->create([
            'plan' => ProspectContract::PLAN_SEMESTRAL,
            'monthly_value_cents' => 180_000,
            'total_value_cents' => 1_080_000,
            'start_date' => now()->startOfMonth()->toDateString(),
            'status' => ProspectContract::STATUS_ACTIVE,
            'billing_mode' => 'pix',
            'payment_method' => 'pix',
        ]);

        $invoice = BillingInvoice::create([
            'number' => 'VAIF-2026-7001',
            'contract_id' => $contract->id,
            'prospect_id' => $this->prospect->id,
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'issue_date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'amount_cents' => 100_000,
            'paid_cents' => 100_000,
            'status' => BillingInvoice::STATUS_PAID,
            'payment_method' => 'pix',
        ]);

        BillingPayment::create([
            'invoice_id' => $invoice->id,
            'contract_id' => $contract->id,
            'prospect_id' => $this->prospect->id,
            'amount_cents' => 100_000,
            'method' => BillingPayment::METHOD_PIX,
            'paid_at' => now()->toDateString(),
            'source' => BillingPayment::SOURCE_MANUAL,
        ]);

        BillingExpense::create([
            'description' => 'Salário',
            'category' => BillingExpense::CATEGORY_PAYROLL,
            'amount_cents' => 20_000,
            'competence_month' => now()->startOfMonth()->toDateString(),
            'due_date' => now()->toDateString(),
            'paid_at' => now()->toDateString(),
            'status' => BillingExpense::STATUS_PAID,
        ]);

        BillingExpense::create([
            'description' => 'Ferramenta',
            'category' => BillingExpense::CATEGORY_SOFTWARE,
            'amount_cents' => 40_000,
            'competence_month' => now()->startOfMonth()->toDateString(),
            'due_date' => now()->addDays(5)->toDateString(),
            'status' => BillingExpense::STATUS_PENDING,
        ]);

        $summary = app(MonthlyFinanceReport::class)->summary(now());

        $this->assertSame(100_000, $summary['invoiced_cents']);
        $this->assertSame(100_000, $summary['received_cents']);
        $this->assertSame(60_000, $summary['expenses_cents']);
        $this->assertSame(20_000, $summary['expenses_paid_cents']);
        $this->assertSame(40_000, $summary['payables_cents']);
        $this->assertSame(0, $summary['overdue_payables_cents']);
        $this->assertSame(80_000, $summary['profit_cash_cents']);
        $this->assertSame(40_000, $summary['profit_accrual_cents']);
        $this->assertSame(180_000, $summary['mrr_cents']);
        $this->assertSame(1, $summary['contracts_count']);

        $this->actingAs($this->staff)
            ->get(route('finance.months', ['month' => now()->format('Y-m')]))
            ->assertOk()
            ->assertSee('Visão mensal')
            ->assertSee('Lucro caixa')
            ->assertSee('Entradas do mês')
            ->assertDontSee('Recebimentos do mês')
            ->assertSee('Saídas do mês')
            ->assertSee('VAIF-2026-7001')
            ->assertSee('Salário')
            ->assertSee('Ferramenta')
            ->assertDontSee('Ver mês')
            ->assertSee('showPicker', false);
    }

    public function test_recurring_expenses_are_cloned_once_per_month(): void
    {
        $expense = BillingExpense::create([
            'description' => 'Aluguel',
            'category' => BillingExpense::CATEGORY_OFFICE,
            'amount_cents' => 300_000,
            'competence_month' => now()->startOfMonth()->toDateString(),
            'due_date' => now()->startOfMonth()->day(5)->toDateString(),
            'status' => BillingExpense::STATUS_PENDING,
            'recurrence' => BillingExpense::RECURRENCE_MONTHLY,
            'recurrence_key' => 'recurring-rent',
        ]);

        $through = now()->startOfMonth()->addMonthNoOverflow()->toDateString();

        $created = app(RecurringExpenseGenerator::class)
            ->generateThrough(\Carbon\Carbon::parse($through));

        $this->assertSame(1, $created);
        $this->assertSame(2, BillingExpense::where('recurrence_key', 'recurring-rent')->count());

        $next = BillingExpense::where('recurrence_key', 'recurring-rent')
            ->whereDate('competence_month', '!=', $expense->competence_month->toDateString())
            ->firstOrFail();

        $this->assertSame(now()->startOfMonth()->addMonthNoOverflow()->toDateString(), $next->competence_month->toDateString());
        $this->assertSame(BillingExpense::STATUS_PENDING, $next->status);
        $this->assertSame(300_000, $next->amount_cents);
        $this->assertSame(5, $next->due_date->day);

        $this->assertSame(0, app(RecurringExpenseGenerator::class)
            ->generateThrough(\Carbon\Carbon::parse($through)));
        $this->assertSame(2, BillingExpense::where('recurrence_key', 'recurring-rent')->count());
    }

    public function test_expenses_and_monthly_view_are_staff_only(): void
    {
        $this->actingAs($this->staff)->get(route('finance.expenses.index'))->assertOk();
        $this->actingAs($this->staff)->get(route('finance.months'))->assertOk();

        $this->actingAs($this->sales)->get(route('finance.expenses.index'))->assertForbidden();
        $this->actingAs($this->sales)->get(route('finance.months'))->assertForbidden();
    }

    public function test_expense_month_must_be_valid(): void
    {
        $this->actingAs($this->staff)
            ->post(route('finance.expenses.store'), [
                'description' => 'Inválida',
                'category' => 'other',
                'amount' => 100,
                'competence_month' => '2026-13',
                'due_date' => now()->toDateString(),
                'status' => 'pending',
                'recurrence' => 'one_off',
            ])
            ->assertSessionHasErrors('competence_month');

        $this->assertSame(0, BillingExpense::count());
    }
}
