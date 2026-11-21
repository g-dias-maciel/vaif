<?php

namespace App\Console\Commands;

use App\Models\FinancePartner;
use Illuminate\Console\Command;

/**
 * One-off convenience for this deployment's two partners. Run it once after
 * the finance migration, then adjust names/shares in Configuração financeira.
 */
class SetupFinancePartners extends Command
{
    protected $signature = 'finance:setup-partners {--force : Cria mesmo que já existam sócios}';

    protected $description = 'Cria os sócios iniciais (Daniel e Gabriel, 50% cada)';

    public function handle(): int
    {
        if (FinancePartner::query()->exists() && ! $this->option('force')) {
            $this->warn('Já existem sócios cadastrados. Use --force para adicionar os dois padrão.');

            return self::SUCCESS;
        }

        foreach ([['Daniel', 5000], ['Gabriel', 5000]] as [$name, $share]) {
            FinancePartner::firstOrCreate(
                ['name' => $name],
                ['share_bps' => $share, 'active' => true],
            );
        }

        $this->info('Sócios iniciais verificados (Daniel e Gabriel). Ajuste as participações em Configuração financeira.');

        return self::SUCCESS;
    }
}
