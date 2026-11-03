<?php

namespace App\Http\Controllers;

use App\Models\ChatMessage;
use App\Models\Lead;
use App\Models\Note;
use App\Models\Project;
use App\Services\DepositService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LeadController extends Controller
{
    /**
     * Detail view of a Lead: contact data plus every Project (tattoo job)
     * attached to this person, with its quotes, bookings and events.
     */
    public function show(Lead $lead)
    {
        $this->authorize('view', $lead);

        $lead->load([
            'notes.author',
            'projects.quotes.promotion',
            'projects.acceptedQuote',
            'projects.payments',
            'projects.calendar',
            'events',
        ]);

        $artist = $this->activeArtist();

        return view('leads.show', [
            'lead' => $lead,
            'pipeline' => config('crm.pipeline_statuses'),
            'chatMessages' => $this->latestChatMessages($lead),
            'eventLabels' => config('crm.event_labels'),
            'fullSelling' => (bool) ($artist?->settings?->full_selling ?? true),
            'chatOwner' => $this->chatOwner($lead),
        ]);
    }

    /**
     * The conversation panel, polled by the lead page so new messages appear
     * without a full reload (and without losing a note being typed).
     */
    public function conversation(Lead $lead)
    {
        $this->authorize('view', $lead);

        return view('leads._conversation', [
            'chatMessages' => $this->latestChatMessages($lead),
            'chatOwner' => $this->chatOwner($lead),
        ]);
    }

    /**
     * `agent` or `artist` — who owns the WhatsApp chat right now.
     */
    private function chatOwner(Lead $lead): string
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return 'agent';
        }

        try {
            $owner = DB::table('chat_sessions')
                ->where('artist_id', $lead->artist_id)
                ->where('lead_id', $lead->id)
                ->orderByDesc('updated_at')
                ->value('owner');

            return $owner === 'artist' ? 'artist' : 'agent';
        } catch (\Throwable $e) {
            return 'agent';
        }
    }

    private function latestChatMessages(Lead $lead)
    {
        // `agent_memory` is Postgres-only (n8n's memory schema); SQLite local
        // dev/tests have no conversation transcript.
        if (DB::connection()->getDriverName() === 'sqlite') {
            return collect();
        }

        // n8n's LangChain memory stores tool envelopes alongside the real
        // messages. They are internal JSON (availability rows, booking rows,
        // etc.) and must never appear in the artist-facing transcript.
        return $lead->chatMessages()
            ->orderByDesc('id')
            ->limit(120)
            ->get()
            ->filter(fn (ChatMessage $message) => $message->is_displayable)
            ->reverse()
            ->values()
            ->take(-50)
            ->values();
    }

    /**
     * Silence or reactivate the SDR for this Lead. The agent still records the
     * inbound message but sends no automatic reply while the flag is set.
     */
    public function toggleAgent(Lead $lead)
    {
        $this->authorize('flagAgent', $lead);

        $blocked = $lead->agent_blocked_at !== null;

        $lead->forceFill([
            'agent_blocked_at' => $blocked ? null : now(),
        ])->save();

        return back()->with('status', $blocked
            ? 'Agente reativado para este cliente.'
            : 'Agente silenciado para este cliente. A Beatriz não responderá mais automaticamente.');
    }

    /**
     * Add an internal note to a Lead.
     */
    public function storeNote(Request $request, Lead $lead)
    {
        $this->authorize('addNote', $lead);

        $data = $request->validate([
            'body' => ['required', 'string'],
        ]);

        Note::create([
            'lead_id' => $lead->id,
            'author_id' => $request->user()->id,
            'body' => $data['body'],
        ]);

        return back()->with('status', 'Nota adicionada.');
    }

    /**
     * Confirm the project's requested deposit from the platform. The agent
     * asks for the signal; the artist confirms it here (lead page or the
     * dashboard alert) after checking the PIX.
     */
    public function confirmDeposit(Project $project, DepositService $deposits)
    {
        $this->authorize('view', $project->lead);

        try {
            $notified = $deposits->confirm(
                $project,
                auth()->user()->isStaff() ? 'staff' : 'artist',
            );
        } catch (\Illuminate\Database\QueryException $e) {
            return back()->with('error', 'Não foi possível confirmar o sinal: nenhum sinal solicitado.');
        }

        return back()->with($notified ? 'status' : 'error', $notified
            ? 'Sinal confirmado. O agendamento está liberado.'
            : 'Sinal confirmado, mas não foi possível avisar o cliente pelo WhatsApp.');
    }

    /**
     * Confirm the tattoo was finished. This is what marks the lead as a
     * returning customer; confirming the sinal alone no longer does.
     */
    public function completeProject(Project $project)
    {
        $this->authorize('complete', $project);

        try {
            DB::select('select * from complete_project(?, ?, ?)', [
                $project->artist_id,
                $project->id,
                auth()->user()->isStaff() ? 'staff' : 'artist',
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            return back()->with('error', 'Não foi possível concluir: projeto perdido ou inexistente.');
        }

        return back()->with('status', 'Tattoo concluída. Cliente marcado como recorrente.');
    }

    /**
     * Manually move a Project to `agendado`.
     *
     * Triage-only artists schedule outside the CRM (the agent never books a
     * slot), so they need a way to advance the pipeline themselves.
     */
    public function scheduleProject(Project $project)
    {
        $this->authorize('schedule', $project);

        if (! in_array($project->stage_key, ['agendado', 'fechado', 'perdido'], true)) {
            DB::select('select * from set_project_stage(?, ?, ?, ?)', [
                $project->artist_id,
                $project->id,
                'agendado',
                auth()->user()->isStaff() ? 'staff' : 'artist',
            ]);
        }

        return back()->with('status', 'Lead marcado como agendado.');
    }

    /**
     * Mark one of the Lead's Projects as lost (perdido).
     */
    public function markLost(Request $request, Project $project)
    {
        $this->authorize('markLost', $project);

        if ($project->stage_key !== 'perdido') {
            DB::select('select * from set_project_stage(?, ?, ?, ?)', [
                $project->artist_id,
                $project->id,
                'perdido',
                'artist',
            ]);
        }

        return back()->with('status', 'Projeto marcado como perdido.');
    }
}
