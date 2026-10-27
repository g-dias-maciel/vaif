<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\Note;
use Illuminate\Http\Request;

class LeadController extends Controller
{
    /**
     * Detail view of a Lead (person) and all their Tattoos.
     */
    public function show(Lead $lead)
    {
        $this->authorize('view', $lead);

        $lead->load(['tattoos.events', 'tattoos.calendar', 'notes.author', 'promotionQuotes.promotion']);

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
}
