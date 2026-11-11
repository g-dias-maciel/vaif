<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\BillingExpense;
use App\Services\Finance\RecurringExpenseGenerator;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class ExpenseController extends Controller
{
    public function index(Request $request)
    {
        $month = $this->monthFromRequest($request);
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();

        $query = BillingExpense::query()
            ->with('creator')
            ->whereBetween('competence_month', [$start->toDateString(), $end->toDateString()])
            ->when($request->filled('status'), function ($query) use ($request): void {
                $request->input('status') === 'overdue'
                    ? $query->overdue()
                    : $query->where('status', $request->input('status'));
            })
            ->when($request->filled('category'), fn ($query) => $query->where('category', $request->input('category')))
            ->when($request->filled('q'), function ($query) use ($request): void {
                $term = '%'.trim((string) $request->input('q')).'%';
                $query->where(function ($inner) use ($term): void {
                    $inner->where('description', 'like', $term)
                        ->orWhere('vendor', 'like', $term);
                });
            })
            ->orderBy('due_date')
            ->orderBy('description');

        $expenses = $query->paginate(50)->withQueryString();

        $base = BillingExpense::query()
            ->whereBetween('competence_month', [$start->toDateString(), $end->toDateString()])
            ->where('status', '!=', BillingExpense::STATUS_CANCELLED);

        $summary = [
            'total' => (int) (clone $base)->sum('amount_cents'),
            'paid' => (int) (clone $base)->where('status', BillingExpense::STATUS_PAID)->sum('amount_cents'),
            'pending' => (int) (clone $base)->where('status', BillingExpense::STATUS_PENDING)->sum('amount_cents'),
            'overdue' => (int) (clone $base)->where('status', BillingExpense::STATUS_PENDING)
                ->whereDate('due_date', '<', now()->toDateString())
                ->sum('amount_cents'),
        ];

        return view('finance.expenses.index', [
            'expenses' => $expenses,
            'summary' => $summary,
            'month' => $start,
            'categories' => BillingExpense::categoryOptions(),
        ]);
    }

    public function create()
    {
        return view('finance.expenses.form', [
            'expense' => new BillingExpense([
                'status' => BillingExpense::STATUS_PENDING,
                'category' => BillingExpense::CATEGORY_OTHER,
                'recurrence' => BillingExpense::RECURRENCE_ONE_OFF,
                'payment_method' => BillingExpense::METHOD_PIX,
                'competence_month' => now()->startOfMonth()->toDateString(),
                'due_date' => now()->toDateString(),
            ]),
            'categories' => BillingExpense::categoryOptions(),
            'methods' => BillingExpense::methodOptions(),
        ]);
    }

    public function copyForm(Request $request)
    {
        $source = $this->parseMonth(
            $request->input('source'),
            now()->subMonthNoOverflow(),
        );
        $target = $this->parseMonth(
            $request->input('target'),
            now(),
        );

        $sourceExpenses = BillingExpense::query()
            ->whereBetween('competence_month', [
                $source->copy()->startOfMonth()->toDateString(),
                $source->copy()->endOfMonth()->toDateString(),
            ])
            ->where('status', '!=', BillingExpense::STATUS_CANCELLED)
            ->orderBy('due_date')
            ->orderBy('description')
            ->get();

        $alreadyCopied = $sourceExpenses->isEmpty()
            ? 0
            : BillingExpense::query()
                ->whereIn('copied_from_id', $sourceExpenses->pluck('id'))
                ->whereBetween('competence_month', [
                    $target->copy()->startOfMonth()->toDateString(),
                    $target->copy()->endOfMonth()->toDateString(),
                ])
                ->count();

        return view('finance.expenses.copy', [
            'source' => $source,
            'target' => $target,
            'sourceExpenses' => $sourceExpenses,
            'sourceTotal' => (int) $sourceExpenses->sum('amount_cents'),
            'alreadyCopied' => $alreadyCopied,
            'sourceHasMonthly' => $sourceExpenses->contains(
                fn (BillingExpense $expense): bool => $expense->recurrence === BillingExpense::RECURRENCE_MONTHLY,
            ),
        ]);
    }

    public function copy(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'source' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'target' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/', 'different:source'],
            'mark_monthly' => ['sometimes', 'boolean'],
        ]);

        $source = $this->parseMonth($data['source'], now()->subMonthNoOverflow());
        $target = $this->parseMonth($data['target'], now());
        $markMonthly = $request->boolean('mark_monthly');

        $sourceExpenses = BillingExpense::query()
            ->whereBetween('competence_month', [
                $source->copy()->startOfMonth()->toDateString(),
                $source->copy()->endOfMonth()->toDateString(),
            ])
            ->where('status', '!=', BillingExpense::STATUS_CANCELLED)
            ->orderBy('due_date')
            ->orderBy('description')
            ->get();

        if ($sourceExpenses->isEmpty()) {
            return back()->with('error', 'Nenhuma despesa encontrada em '.$source->format('m/Y').'.');
        }

        $copied = 0;
        $skipped = 0;

        DB::beginTransaction();

        try {
            foreach ($sourceExpenses as $expense) {
                $alreadyExists = BillingExpense::query()
                    ->where('copied_from_id', $expense->id)
                    ->whereBetween('competence_month', [
                        $target->copy()->startOfMonth()->toDateString(),
                        $target->copy()->endOfMonth()->toDateString(),
                    ])
                    ->exists();

                if ($alreadyExists) {
                    $skipped++;

                    continue;
                }

                $recurrenceKey = null;

                if ($markMonthly) {
                    $recurrenceKey = $expense->recurrence_key ?: (string) Str::uuid();

                    $keyExists = BillingExpense::query()
                        ->where('recurrence_key', $recurrenceKey)
                        ->whereBetween('competence_month', [
                            $target->copy()->startOfMonth()->toDateString(),
                            $target->copy()->endOfMonth()->toDateString(),
                        ])
                        ->exists();

                    if ($keyExists) {
                        $skipped++;

                        continue;
                    }
                }

                $day = min(
                    $expense->due_date?->day ?? $target->daysInMonth,
                    $target->daysInMonth,
                );

                $notes = trim((string) $expense->notes);
                $notes = trim($notes.' Copiado de '.$expense->competence_month->format('m/Y').'.');

                BillingExpense::create([
                    'description' => $expense->description,
                    'vendor' => $expense->vendor,
                    'category' => $expense->category,
                    'amount_cents' => $expense->amount_cents,
                    'competence_month' => $target->copy()->startOfMonth()->toDateString(),
                    'due_date' => $target->copy()->startOfMonth()->day($day)->toDateString(),
                    'paid_at' => null,
                    'status' => BillingExpense::STATUS_PENDING,
                    'payment_method' => $expense->payment_method,
                    'recurrence' => $markMonthly
                        ? BillingExpense::RECURRENCE_MONTHLY
                        : BillingExpense::RECURRENCE_ONE_OFF,
                    'recurrence_key' => $recurrenceKey,
                    'notes' => $notes !== '' ? $notes : null,
                    'created_by' => $request->user()?->id,
                    'copied_from_id' => $expense->id,
                ]);

                $copied++;
            }

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            report($e);

            return back()->with('error', 'Não foi possível copiar as despesas: '.$e->getMessage());
        }

        return redirect()
            ->route('finance.expenses.index', ['month' => $target->format('Y-m')])
            ->with('status', sprintf(
                '%d despesa(s) copiadas de %s para %s.%s',
                $copied,
                $source->format('m/Y'),
                $target->format('m/Y'),
                $skipped > 0 ? ' '.$skipped.' já existiam.' : '',
            ));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['created_by'] = $request->user()?->id;

        BillingExpense::create($data);

        return redirect()
            ->route('finance.expenses.index', ['month' => Carbon::parse($data['competence_month'])->format('Y-m')])
            ->with('status', 'Despesa registrada.');
    }

    public function edit(BillingExpense $expense)
    {
        return view('finance.expenses.form', [
            'expense' => $expense,
            'categories' => BillingExpense::categoryOptions(),
            'methods' => BillingExpense::methodOptions(),
        ]);
    }

    public function update(Request $request, BillingExpense $expense): RedirectResponse
    {
        $data = $this->validated($request, $expense);

        $expense->update($data);

        return redirect()
            ->route('finance.expenses.index', ['month' => $expense->fresh()->competence_month->format('Y-m')])
            ->with('status', 'Despesa atualizada.');
    }

    public function markPaid(BillingExpense $expense): RedirectResponse
    {
        $expense->update([
            'status' => BillingExpense::STATUS_PAID,
            'paid_at' => $expense->paid_at ?? now()->toDateString(),
        ]);

        return back()->with('status', 'Despesa marcada como paga.');
    }

    public function cancel(BillingExpense $expense): RedirectResponse
    {
        $expense->update(['status' => BillingExpense::STATUS_CANCELLED]);

        return back()->with('status', 'Despesa cancelada.');
    }

    public function destroy(BillingExpense $expense): RedirectResponse
    {
        $month = $expense->competence_month->format('Y-m');
        $expense->delete();

        return redirect()
            ->route('finance.expenses.index', ['month' => $month])
            ->with('status', 'Despesa excluída.');
    }

    public function generateRecurring(Request $request, RecurringExpenseGenerator $generator): RedirectResponse
    {
        $through = now()->startOfMonth()->addMonthNoOverflow();
        $created = $generator->generateThrough($through, $request->user());

        return back()->with('status', $created > 0
            ? "{$created} despesa(s) recorrente(s) geradas até ".$through->format('m/Y').'.'
            : 'Nenhuma despesa recorrente nova para gerar.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?BillingExpense $expense = null): array
    {
        $data = $request->validate([
            'description' => ['required', 'string', 'max:255'],
            'vendor' => ['nullable', 'string', 'max:255'],
            'category' => ['required', Rule::in(array_keys(BillingExpense::categoryOptions()))],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'competence_month' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'due_date' => ['required', 'date'],
            'paid_at' => ['nullable', 'date'],
            'status' => ['required', Rule::in([
                BillingExpense::STATUS_PENDING,
                BillingExpense::STATUS_PAID,
                BillingExpense::STATUS_CANCELLED,
            ])],
            'payment_method' => ['nullable', Rule::in(array_keys(BillingExpense::methodOptions()))],
            'recurrence' => ['required', Rule::in([
                BillingExpense::RECURRENCE_ONE_OFF,
                BillingExpense::RECURRENCE_MONTHLY,
            ])],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $month = Carbon::createFromFormat('Y-m', $data['competence_month'])->startOfMonth();
        $paidAt = $data['paid_at'] ?? null;

        if ($data['status'] === BillingExpense::STATUS_PAID && blank($paidAt)) {
            $paidAt = now()->toDateString();
        }

        if ($data['status'] !== BillingExpense::STATUS_PAID) {
            $paidAt = null;
        }

        $recurrenceKey = $expense?->recurrence_key;

        if ($data['recurrence'] === BillingExpense::RECURRENCE_MONTHLY && blank($recurrenceKey)) {
            $recurrenceKey = (string) Str::uuid();
        }

        return [
            'description' => $data['description'],
            'vendor' => $data['vendor'] ?? null,
            'category' => $data['category'],
            'amount_cents' => (int) round(((float) $data['amount']) * 100),
            'competence_month' => $month->toDateString(),
            'due_date' => $data['due_date'],
            'paid_at' => $paidAt,
            'status' => $data['status'],
            'payment_method' => $data['payment_method'] ?? null,
            'recurrence' => $data['recurrence'],
            'recurrence_key' => $recurrenceKey,
            'notes' => $data['notes'] ?? null,
        ];
    }

    private function monthFromRequest(Request $request): Carbon
    {
        $value = (string) $request->input('month', now()->format('Y-m'));

        try {
            return Carbon::createFromFormat('Y-m', $value)->startOfMonth();
        } catch (Throwable) {
            return now()->startOfMonth();
        }
    }

    private function parseMonth(mixed $value, Carbon $fallback): Carbon
    {
        try {
            return Carbon::createFromFormat('Y-m', (string) $value)->startOfMonth();
        } catch (Throwable) {
            return $fallback->copy()->startOfMonth();
        }
    }
}
