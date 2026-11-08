<?php

namespace App\Console\Commands;

use App\Services\Agent\Eval\Evaluator;
use App\Services\Agent\Eval\Scenario;
use App\Services\Agent\Eval\ScriptedAiClient;
use Illuminate\Console\Command;

/**
 * Fast, offline fake-case run.
 *
 * The pipeline is production code (prompt, validator, repair, apply), only the
 * model answers come from the scenario file. Use it on every prompt/validator
 * change; `agent:eval` is the slower check against the real model.
 */
class AgentSimulate extends Command
{
    protected $signature = 'agent:simulate
        {--scenario= : Path to one scenario JSON file}
        {--dir= : Directory of scenarios}';

    protected $description = 'Run scripted agent fake-cases through the real grounded pipeline';

    public function handle(): int
    {
        $path = (string) ($this->option('scenario') ?: $this->option('dir') ?: config('agent.eval.scenarios'));

        $scenarios = Scenario::load($path);
        if ($scenarios === []) {
            $this->error("No scenarios found at {$path}.");

            return self::FAILURE;
        }

        $rows = [];
        $failed = 0;

        foreach ($scenarios as $scenario) {
            $client = new ScriptedAiClient($scenario->script());
            $report = (new Evaluator($client, 'scripted'))->run($scenario);

            if (! $report['pass']) {
                $failed++;
            }

            $rows[] = [
                $report['scenario'],
                $report['pass'] ? 'PASS' : 'FAIL',
                $report['resolution'] ?? '-',
                $report['repaired'] ? 'yes' : 'no',
                $this->clip((string) ($report['reply'] ?? '')),
                implode(' | ', $report['failures'] ?? []),
            ];
        }

        $this->table(['scenario', 'result', 'resolution', 'repaired', 'reply', 'failures'], $rows);

        if ($failed > 0) {
            $this->error("{$failed} of ".count($scenarios).' scenario(s) failed.');

            return self::FAILURE;
        }

        $this->info(count($scenarios).' scenario(s) passed.');

        return self::SUCCESS;
    }

    private function clip(string $value): string
    {
        $value = preg_replace('/\s+/', ' ', trim($value)) ?? $value;

        return mb_strlen($value) > 80 ? mb_substr($value, 0, 77).'...' : $value;
    }
}
