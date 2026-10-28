<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\Note;
use App\Models\Project;
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

        return view('leads.show', [
            'lead' => $lead,
            'pipeline' => config('crm.pipeline_statuses'),
            'chatMessages' => $this->latestChatMessages($lead),
            'eventLabels' => config('crm.event_labels'),
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
        ]);
    }

    private function latestChatMessages(Lead $lead)
    {
        // `agent_memory` is Postgres-only (n8n's memory schema); SQLite local
        // dev/tests have no conversation transcript.
        if (DB::connection()->getDriverName() === 'sqlite') {
            return collect();
        }

        return $lead->chatMessages()
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->reverse()
            ->values();
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
