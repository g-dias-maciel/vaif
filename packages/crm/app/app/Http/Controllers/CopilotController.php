<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateCopilotReport;
use App\Models\CopilotReport;
use App\Services\Copilot\CopilotReporter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Staff-only Copilot: ops daily and calibration weekly reports.
 *
 * Phase 1 is read-only. "Generate" only queues a report; no WAHA, mode or
 * prompt action is executed from here.
 */
class CopilotController extends Controller
{
    public function index()
    {
        $schemaReady = $this->schemaReady();

        $latest = $schemaReady
            ? CopilotReport::query()->orderByDesc('created_at')->limit(20)->get()
            : collect();

        return view('copilot.index', [
            'schemaReady' => $schemaReady,
            'latestOps' => $schemaReady ? CopilotReport::query()->where('kind', CopilotReport::KIND_OPS)->orderByDesc('created_at')->first() : null,
            'latestCalibration' => $schemaReady ? CopilotReport::query()->where('kind', CopilotReport::KIND_CALIBRATION)->orderByDesc('created_at')->first() : null,
            'reports' => $latest,
        ]);
    }

    public function show(CopilotReport $report)
    {
        return view('copilot.show', ['report' => $report]);
    }

    public function generate(Request $request, CopilotReporter $reporter)
    {
        $data = $request->validate([
            'kind' => ['required', 'in:ops,calibration'],
        ]);

        if (! $this->schemaReady()) {
            return back()->with('error', 'A migração do Copiloto ainda não foi aplicada (0034_copilot_reports.sql).');
        }

        $kind = (string) $data['kind'];
        $period = $reporter->period($kind);

        $existing = CopilotReport::query()
            ->where('kind', $kind)
            ->where('period_key', $period['key'])
            ->first();

        if ($existing && $existing->status === CopilotReport::STATUS_QUEUED) {
            return back()->with('status', 'Este relatório já está sendo gerado.');
        }

        CopilotReport::updateOrCreate(
            ['kind' => $kind, 'period_key' => $period['key']],
            [
                'period_start' => $period['start'],
                'period_end' => $period['end'],
                'status' => CopilotReport::STATUS_QUEUED,
                'facts' => [],
                'narrative' => null,
                'findings' => [],
                'model' => null,
                'generated_at' => null,
                'delivered_at' => null,
            ],
        );

        GenerateCopilotReport::dispatch($kind, $period['key'], false)
            ->onQueue((string) config('agent.queue', 'agent'));

        return back()->with('status', 'Relatório em geração. Atualize a página em alguns segundos.');
    }

    private function schemaReady(): bool
    {
        try {
            return Schema::hasTable('copilot_reports');
        } catch (\Throwable $e) {
            return false;
        }
    }
}
