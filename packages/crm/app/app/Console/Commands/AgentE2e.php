<?php

namespace App\Console\Commands;

use App\Services\Agent\Eval\ConversationEvaluator;
use App\Services\Agent\Eval\ConversationScenario;
use App\Services\Agent\OpenRouterClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * End-to-end fake conversations against the real model and real Postgres RPCs.
 *
 * Each scenario is a multi-turn WhatsApp chat with a synthetic lead. The
 * conversation model, preflight, prompt, validator, state machine and handoff
 * silence are production code. The whole scenario runs in a transaction that
 * is always rolled back.
 */
class AgentE2e extends Command
{
    protected $signature = 'agent:e2e
        {--scenario= : Path to one conversation JSON file}
        {--dir= : Directory of conversations}
        {--model= : Override the conversation model}
        {--output= : Report directory}';

    protected $description = 'Run fake multi-turn conversations end-to-end against the real model';

    public function handle(): int
    {
        if ((string) config('agent.openrouter.key') === '') {
            $this->error('OPENROUTER_API_KEY is not configured.');

            return self::FAILURE;
        }

        $path = (string) ($this->option('scenario') ?: $this->option('dir') ?: resource_path('agent-e2e'));
        $model = (string) ($this->option('model') ?: config('agent.models.agent'));
        config(['agent.models.agent' => $model]);

        $scenarios = ConversationScenario::load($path);

        if ($scenarios === []) {
            $this->error("No conversations found at {$path}.");

            return self::FAILURE;
        }

        $evaluator = new ConversationEvaluator(new OpenRouterClient(), $model);
        $reports = [];
        $rows = [];
        $failed = 0;

        foreach ($scenarios as $scenario) {
            $this->line("▶ {$scenario->name()}");
            $report = $evaluator->run($scenario);
            $report['model'] = $model;
            $reports[] = $report;

            if (! $report['pass']) {
                $failed++;
            }

            foreach ($report['turns'] as $turn) {
                $rows[] = [
                    $scenario->name(),
                    $turn['turn'],
                    $report['pass'] ? 'PASS' : 'FAIL',
                    $turn['skipped'] ?? '-',
                    $turn['intent'] ?? '-',
                    $turn['resolution'] ?? '-',
                    $turn['latency_ms'].'ms',
                    $this->clip((string) ($turn['reply'] ?? '')),
                    implode(' | ', array_filter(
                        $report['failures'],
                        fn ($failure) => str_starts_with($failure, 'turn '.$turn['turn'].':'),
                    )),
                ];
            }

            if ($report['turns'] === []) {
                $rows[] = [$scenario->name(), '-', 'FAIL', '-', '-', '-', '-', '-', implode(' | ', $report['failures'])];
            }
        }

        $this->newLine();
        $this->table(
            ['scenario', 'turn', 'result', 'skipped', 'intent', 'resolution', 'latency', 'reply', 'failures'],
            $rows,
        );

        $dir = (string) ($this->option('output') ?: storage_path('app/agent-e2e'));
        File::ensureDirectoryExists($dir);
        $file = rtrim($dir, '/').'/'.date('Ymd-His').'.json';
        File::put($file, json_encode([
            'model' => $model,
            'created_at' => date('c'),
            'passed' => count($scenarios) - $failed,
            'failed' => $failed,
            'conversations' => $reports,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->info("Report: {$file}");

        if ($failed > 0) {
            $this->error("{$failed} of ".count($scenarios).' conversation(s) failed.');

            return self::FAILURE;
        }

        $this->info(count($scenarios).' conversation(s) passed.');

        return self::SUCCESS;
    }

    private function clip(string $value): string
    {
        $value = preg_replace('/\s+/', ' ', trim($value)) ?? $value;

        return mb_strlen($value) > 80 ? mb_substr($value, 0, 77).'...' : $value;
    }
}
