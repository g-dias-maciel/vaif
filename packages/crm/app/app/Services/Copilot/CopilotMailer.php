<?php

namespace App\Services\Copilot;

use App\Mail\CopilotReportMail;
use App\Models\CopilotReport;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class CopilotMailer
{
    /**
     * @return array<int, string>
     */
    public function recipients(): array
    {
        $configured = (array) config('copilot.recipients', []);

        $emails = $configured !== []
            ? $configured
            : User::query()->where('role', User::ROLE_STAFF)->pluck('email')->all();

        return array_values(array_unique(array_filter(
            array_map('trim', $emails),
            fn (string $email) => filter_var($email, FILTER_VALIDATE_EMAIL) !== false,
        )));
    }

    public function send(CopilotReport $report): int
    {
        if (! (bool) config('copilot.mail', true) || ! $report->isReady()) {
            return 0;
        }

        $emails = $this->recipients();

        if ($emails === []) {
            Log::warning('Copilot report has no staff recipients', ['report_id' => $report->id]);

            return 0;
        }

        try {
            Mail::to($emails)->send(new CopilotReportMail($report));
        } catch (\Throwable $e) {
            Log::warning('Copilot report e-mail failed', [
                'report_id' => $report->id,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }

        $report->forceFill(['delivered_at' => now()])->save();

        return count($emails);
    }
}
