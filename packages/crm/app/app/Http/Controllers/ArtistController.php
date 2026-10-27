<?php

namespace App\Http\Controllers;

use App\Models\Artist;
use App\Models\CalendarSlot;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class ArtistController extends Controller
{
    /**
     * Staff: list all Artists with their lead counts.
     */
    public function index()
    {
        $artists = Artist::withCount('leads')
            ->orderBy('nome')
            ->get();

        return view('artists.index', ['artists' => $artists]);
    }

    /**
     * Staff: full-access view of a single Artist's pipeline + bookings.
     */
    public function show(Artist $artist)
    {
        session(['staff_artist_id' => $artist->id]);

        $leads = Lead::query()
            ->where('artist_id', $artist->id)
            ->orderByDesc('updated_at')
            ->get();

        $bookings = CalendarSlot::with('lead')
            ->where('artist_id', $artist->id)
            ->where('type', 'booked')
            ->where('start_at', '>=', now())
            ->orderBy('start_at')
            ->get();

        return view('artists.show', [
            'artist' => $artist,
            'leads' => $leads,
            'bookings' => $bookings,
            'pipeline' => config('crm.pipeline_statuses'),
        ]);
    }

    /**
     * Staff: pause / reactivate the SDR agent for any Artist.
     */
    public function toggleAgent(Artist $artist)
    {
        return back()->with('status', ($artist->nome ?: $artist->display_name).' — '.$artist->toggleAgent());
    }

    /**
     * Staff: select an Artist to operate on (opens their CRM tabs).
     */
    public function select(Artist $artist)
    {
        session(['staff_artist_id' => $artist->id]);

        return redirect()->route('dashboard')->with('status', 'Visualizando: '.($artist->nome ?: $artist->display_name));
    }

    /**
     * Staff: form to create a new Artist (provisions the CRM + a login).
     */
    public function create()
    {
        return view('artists.create');
    }

    /**
     * Staff: create the Artist, their CRM login, and default settings.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'nome' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'whatsapp_number' => ['nullable', 'string', 'max:32'],
            'instagram_handle' => ['nullable', 'string', 'max:255'],
            'wa_session_slug' => ['nullable', 'string', 'max:255', 'unique:artists,wa_session_slug'],
            'deposit_value' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        $artist = Artist::create([
            'nome' => $data['nome'],
            'display_name' => $data['nome'],
            'status' => 'live',
            'timezone' => $data['timezone'] ?? 'America/Sao_Paulo',
            'whatsapp_number' => $data['whatsapp_number'] ?? null,
            'instagram_handle' => $data['instagram_handle'] ?? null,
            'wa_session_slug' => $data['wa_session_slug'] ?? null,
            'deposit_type' => 'percent',
            'deposit_value' => $data['deposit_value'] ?? 30,
            'working_hours' => $this->defaultWorkingHours(),
        ]);

        User::create([
            'name' => $data['nome'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => User::ROLE_ARTIST,
            'artist_id' => $artist->id,
        ]);

        return redirect()->route('artists.show', $artist)->with('status', 'Artista criado com acesso ao CRM.');
    }

    private function defaultWorkingHours(): array
    {
        return [
            'seg' => ['09:00-12:00', '14:00-18:00'],
            'ter' => ['09:00-12:00', '14:00-18:00'],
            'qua' => ['09:00-12:00', '14:00-18:00'],
            'qui' => ['09:00-12:00', '14:00-18:00'],
            'sex' => ['09:00-12:00', '14:00-18:00'],
            'sab' => ['09:00-13:00'],
        ];
    }
}
