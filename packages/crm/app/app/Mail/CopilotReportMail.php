<?php

namespace App\Mail;

use App\Models\CopilotReport;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class CopilotReportMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public CopilotReport $report)
    {
    }

    public function build(): self
    {
        $label = $this->report->label();
        $date = ($this->report->period_end ?? $this->report->generated_at)?->format('d/m/Y');

        return $this
            ->subject('[Copiloto] '.$label.($date ? ' — '.$date : ''))
            ->view('emails.copilot-report', ['report' => $this->report]);
    }
}
