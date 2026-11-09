<?php

namespace App\Console\Commands;

use App\Services\Finance\RecurringExpenseGenerator;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Clones monthly recurring expenses into every month up to the target month.
 * Safe to run daily: the generator skips months that already exist.
 */
class GenerateRecurringExpenses extends Command
{
    protected $signature = 'finance:generate-recurring-expenses
        {--through= : Gera até este mês (Y-m; padrão: próximo mês)}
        {--dry-run : Relata sem gravar}';

    protected $description = 'Gera as despesas recorrentes mensais até o mês alvo';

    public function handle(RecurringExpenseGenerator $generator): int
    {
        $through = $this->option('through')
            ? Carbon::createFromFormat('Y-m', (string) $this->option('through'))->startOfMonth()
            : now()->startOfMonth()->addMonthNoOverflow();

        $dryRun = (bool) $this->option('dry-run');

        DB::beginTransaction();

        try {
            $created = $generator->generateThrough($through);
            $dryRun ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Falha ao gerar despesas recorrentes: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->warn('Dry-run: nada foi gravado.');
        }

        $this->info($created > 0
            ? "{$created} despesa(s) recorrente(s) geradas até ".$through->format('m/Y').'.'
            : 'Nenhuma despesa recorrente nova para gerar.');

        return self::SUCCESS;
    }
}
