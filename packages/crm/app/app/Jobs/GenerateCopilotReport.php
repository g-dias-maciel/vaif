<?php

namespace App\Jobs;

use App\Models\CopilotReport;
use App\Services\Copilot\CopilotMailer;
use App\Services\Copilot\CopilotReporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateCopilotReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    /** @var array<int, int> */
    public array $backoff = [10];

    public function __construct(
        public readonly string $kind,
        public readonly string $periodKey,
        public readonly bool $mail = false,
    ) {
    }

    public function handle(CopilotReporter $reporter, CopilotMailer $mailer): void
    {
        $report = $reporter->generate($this->kind, $this->periodKey);

        if ($this->mail) {
            $mailer->send($report);
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('Copilot report job failed', [
            'kind' => $this->kind,
            'period_key' => $this->periodKey,
            'error' => $e->getMessage(),
        ]);

        CopilotReport::query()
            ->where('kind', $this->kind)
            ->where('period_key', $this->periodKey)
            ->where('status', '!=', CopilotReport::STATUS_READY)
            ->update([
                'status' => CopilotReport::STATUS_FAILED,
                'narrative' => 'Não foi possível gerar este relatório: '.$e->getMessage(),
            ]);
    }
}
