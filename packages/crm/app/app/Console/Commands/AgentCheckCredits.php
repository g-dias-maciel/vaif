<?php

namespace App\Console\Commands;

use App\Services\Agent\CreditMonitor;
use Illuminate\Console\Command;

class AgentCheckCredits extends Command
{
    protected $signature = 'agent:check-credits
        {--force : Send a test alert even when the balance is healthy}';

    protected $description = 'Check OpenRouter credits and warn the admin when the balance is low';

    public function handle(CreditMonitor $monitor): int
    {
        $result = $monitor->checkAndAlert((bool) $this->option('force'));
        $balance = $result['balance'];

        if ($balance === null) {
            $this->error('Could not read the OpenRouter credit balance.');

            return self::FAILURE;
        }

        $this->table(
            ['Total', 'Usage', 'Remaining', 'Alerted'],
            [[
                number_format($balance['total'], 2, ',', '.'),
                number_format($balance['usage'], 2, ',', '.'),
                number_format($balance['remaining'], 2, ',', '.'),
                $result['alerted'] ? 'yes' : 'no',
            ]],
        );

        if ($balance['remaining'] <= (float) config('agent.credits.warn_below', 5.0)) {
            $this->warn('Credit balance is below the warning threshold.');
        }

        return self::SUCCESS;
    }
}
