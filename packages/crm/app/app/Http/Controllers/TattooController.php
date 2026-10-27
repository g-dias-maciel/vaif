<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Tattoo;
use Illuminate\Http\Request;

class TattooController extends Controller
{
    /**
     * Detail view of a single Tattoo project.
     */
    public function show(Tattoo $tattoo)
    {
        $this->authorize('view', $tattoo);

        $tattoo->load(['lead.notes.author', 'lead.tattoos', 'lead.promotionQuotes.promotion', 'events', 'calendar']);

        return view('tattoos.show', [
            'tattoo' => $tattoo,
            'pipeline' => config('crm.pipeline_statuses'),
        ]);
    }

    /**
     * Mark a Tattoo project as lost (perdido).
     */
    public function markLost(Request $request, Tattoo $tattoo)
    {
        $this->authorize('markLost', $tattoo);

        if ($tattoo->pipeline_status !== 'perdido') {
            $tattoo->update(['pipeline_status' => 'perdido']);

            Event::create([
                'lead_id' => $tattoo->lead_id,
                'tattoo_id' => $tattoo->id,
                'artist_id' => $tattoo->artist_id,
                'event_type' => 'tattoo_lost',
                'payload' => ['actor' => 'artist'],
            ]);
        }

        return back()->with('status', 'Projeto marcado como perdido.');
    }
}
