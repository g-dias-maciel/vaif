<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use Illuminate\Support\Facades\DB;

class ClientController extends Controller
{
    /**
     * The Artist's client book: every Lead, with a live name/phone search and
     * a pipeline-status filter (all client-side so typing never submits).
     */
    public function index()
    {
        $artist = $this->activeArtist();

        if (! $artist) {
            return redirect()->route('artists.index');
        }

        $leads = $artist->leads()
            ->whereNull('anonymized_at')
            ->with('latestProject')
            ->orderByDesc('last_message_at')
            ->orderByDesc('created_at')
            ->get();

        $returningPhones = DB::table('returning_customers')
            ->where('artist_id', $artist->id)
            ->pluck('phone_e164')
            ->all();

        return view('clients.index', [
            'artist' => $artist,
            'leads' => $leads,
            'pipeline' => config('crm.pipeline_statuses'),
            'returningPhones' => $returningPhones,
        ]);
    }

    /**
     * Permanently remove a client and all attached history. The UI confirms
     * this in a lightbox before posting.
     */
    public function destroy(Lead $lead)
    {
        $this->authorize('delete', $lead);

        $deleted = DB::selectOne('select delete_lead(?, ?, ?) as deleted', [
            $lead->artist_id,
            $lead->id,
            auth()->user()->isStaff() ? 'staff' : 'artist',
        ])->deleted;

        if (! $deleted) {
            return redirect()->route('clients.index')
                ->with('error', 'Não foi possível excluir: cliente não encontrado.');
        }

        return redirect()->route('clients.index')->with('lightbox', [
            'title' => 'Cliente excluído',
            'message' => 'O cliente e todo o histórico dele foram removidos.',
        ]);
    }
}
