<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Lead;
use App\Models\Note;
use Illuminate\Http\Request;

class LeadController extends Controller
{
    /**
     * Detail view of a Lead: contact, the single tattoo project attached to
     * the conversation, its bookings/events, promotion quotes and notes.
     */
    public function show(Lead $lead)
    {
        $this->authorize('view', $lead);

        $lead->load(['notes.author', 'promotionQuotes.promotion', 'calendar', 'events']);

        return view('leads.show', [
            'lead' => $lead,
            'pipeline' => config('crm.pipeline_statuses'),
        ]);
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
     * Mark a Lead's project as lost (perdido).
     */
    public function markLost(Request $request, Lead $lead)
    {
        $this->authorize('markLost', $lead);

        if ($lead->pipeline_status !== 'perdido') {
            $lead->update(['pipeline_status' => 'perdido']);

            Event::create([
                'lead_id' => $lead->id,
                'artist_id' => $lead->artist_id,
                'event_type' => 'lead_lost',
                'payload' => ['actor' => 'artist'],
            ]);
        }

        return back()->with('status', 'Projeto marcado como perdido.');
    }
}
