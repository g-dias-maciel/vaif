<?php

namespace App\Console\Commands;

use App\Services\Agent\Eval\Evaluator;
use App\Services\Agent\Eval\Scenario;
use App\Services\Agent\OpenRouterClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Fake cases against the real model.
 *
 * The CRM fixtures are fake, but the preflight, the prompt, the agent, the
 * claim extractor and the validator are exactly what runs in production. The
 * report is written to storage/app/agent-evals so a prompt change can be
 * compared before it reaches Adriano.
 */
class AgentEval extends Command
{
    protected $signature = 'agent:eval
        {--scenario= : Path to one scenario JSON file}
        {--dir= : Directory of scenarios}
        {--model= : Override the eval model}
        {--output= : Report directory}';

    protected $description = 'Run fake agent cases against the real model and write a report';

    public function handle(): int
    {
        $key = (string) config('agent.openrouter.key');
        if ($key === '') {
            $this->error('OPENROUTER_API_KEY is not configured.');

            return self::FAILURE;
        }

        $path = (string) ($this->option('scenario') ?: $this->option('dir') ?: config('agent.eval.scenarios'));
        $model = (string) ($this->option('model') ?: config('agent.models.eval'));

        $scenarios = Scenario::load($path);
        if ($scenarios === []) {
            $this->error("No scenarios found at {$path}.");

            return self::FAILURE;
        }

        $client = new OpenRouterClient();
        $evaluator = new Evaluator($client, $model);

        $rows = [];
        $reports = [];
        $failed = 0;

        foreach ($scenarios as $scenario) {
            $this->line("▶ {$scenario->name()}");
            $report = $evaluator->run($scenario);
            $report['model'] = $model;

            if (! $report['pass']) {
                $failed++;
            }

            $reports[] = $report;
            $rows[] = [
                $report['scenario'],
                $report['pass'] ? 'PASS' : 'FAIL',
                $report['resolution'] ?? '-',
                $report['repaired'] ? 'yes' : 'no',
                $report['latency_ms'].'ms',
                $this->clip((string) ($report['reply'] ?? '')),
                implode(' | ', $report['failures'] ?? []),
            ];
        }

        $this->newLine();
        $this->table(['scenario', 'result', 'resolution', 'repaired', 'latency', 'reply', 'failures'], $rows);

        $dir = (string) ($this->option('output') ?: config('agent.eval.output'));
        File::ensureDirectoryExists($dir);
        $file = rtrim($dir, '/').'/'.date('Ymd-His').'.json';
        File::put($file, json_encode([
            'model' => $model,
            'created_at' => date('c'),
            'passed' => count($scenarios) - $failed,
            'failed' => $failed,
            'scenarios' => $reports,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->info("Report: {$file}");

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
