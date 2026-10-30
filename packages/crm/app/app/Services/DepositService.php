<?php

namespace App\Services;

use App\Models\CalendarEntry;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Confirms a requested deposit and tells the lead on WhatsApp that the slot is
 * now confirmed. Shared by the lead page, the dashboard and the bookings page.
 */
class DepositService
{
    /**
     * @return bool Whether the WhatsApp confirmation could be sent.
     */
    public function confirm(Project $project, string $actor = 'artist', string $method = 'manual'): bool
    {
        // Double-clicking the confirmation button must not send the WhatsApp
        // confirmation twice.
        $alreadyConfirmed = $project->deposit?->isConfirmed() === true;

        DB::select('select * from confirm_deposit(?, ?, ?, ?, ?)', [
            $project->artist_id,
            $project->id,
            $method,
            null,
            $actor,
        ]);

        if ($alreadyConfirmed) {
            return true;
        }

        return $this->notifyConfirmed($project->fresh(['artist.settings', 'lead']));
    }

    public function notifyConfirmed(Project $project): bool
    {
        $artist = $project->artist;
        $lead = $project->lead;

        if (! $artist || ! $lead) {
            return false;
        }

        $booking = CalendarEntry::query()
            ->where('artist_id', $project->artist_id)
            ->where('project_id', $project->id)
            ->where('kind', 'booking')
            ->where('active', true)
            ->orderByDesc('start_at')
            ->first();

        $name = $artist->displayName();
        $timezone = $artist->timezone ?: 'America/Sao_Paulo';

        $when = null;
        if ($booking) {
            $local = $booking->start_at->copy()->setTimezone($timezone);
            $when = $local->format('d/m/Y').' às '.$local->format('H:i');
        }

        $text = $when
            ? "Sinal recebido! Seu agendamento está confirmado para {$when}. O {$name} te espera lá!"
            : "Sinal recebido! Seu agendamento está confirmado. O {$name} te espera lá!";

        return WahaService::make()->sendText($artist, $this->chatId($project), $text);
    }

    private function chatId(Project $project): string
    {
        // Reuse the exact chat WAHA uses for this lead (LID or phone). Falls
        // back to the phone when the conversation started before chat_sessions
        // existed.
        try {
            $stored = DB::table('chat_sessions')
                ->where('artist_id', $project->artist_id)
                ->where('lead_id', $project->lead_id)
                ->orderByDesc('updated_at')
                ->value('chat_id');
        } catch (\Throwable $e) {
            // SQLite local tests have no chat_sessions table.
            $stored = null;
        }

        if (is_string($stored) && $stored !== '') {
            return Str::after($stored, ':');
        }

        $digits = preg_replace('/\D+/', '', (string) $project->lead?->phone_e164);

        return $digits !== '' ? $digits.'@c.us' : '';
    }
}
