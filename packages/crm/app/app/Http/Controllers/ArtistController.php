<?php

namespace App\Http\Controllers;

use App\Mail\ArtistInvite;
use App\Models\Artist;
use App\Models\CalendarEntry;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class ArtistController extends Controller
{
    /**
     * Staff: list all Artists with their lead counts.
     */
    public function index()
    {
        $artists = Artist::withCount('leads')
            ->orderBy('name')
            ->get();

        return view('artists.index', ['artists' => $artists]);
    }

    /**
     * Staff: full-access view of a single Artist's pipeline + bookings.
     */
    public function show(Artist $artist)
    {
        session(['staff_artist_id' => $artist->id]);

        $projects = Project::with(['lead', 'acceptedQuote', 'latestQuote', 'payments', 'calendar'])
            ->where('artist_id', $artist->id)
            ->orderByDesc('updated_at')
            ->get();

        $bookings = CalendarEntry::with(['lead', 'project'])
            ->where('artist_id', $artist->id)
            ->where('kind', 'booking')
            ->where('active', true)
            ->where('start_at', '>=', now())
            ->orderBy('start_at')
            ->get();

        return view('artists.show', [
            'artist' => $artist,
            'projects' => $projects,
            'bookings' => $bookings,
            'pipeline' => config('crm.pipeline_statuses'),
        ]);
    }

    /**
     * Staff: pause / reactivate the SDR agent for any Artist.
     */
    public function toggleAgent(Artist $artist)
    {
        return back()->with('status', $artist->displayName().' — '.$artist->toggleAgent());
    }

    /**
     * Staff: select an Artist to operate on (opens their CRM tabs).
     */
    public function select(Artist $artist)
    {
        session(['staff_artist_id' => $artist->id]);

        return redirect()->route('dashboard')->with('status', 'Visualizando: '.$artist->displayName());
    }

    /**
     * Staff: form to create a new Artist (provisions the CRM + a login).
     */
    public function create()
    {
        return view('artists.create');
    }

    /**
     * Staff: create the Artist, their settings, a CRM login, and default hours.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'whatsapp_number' => ['nullable', 'string', 'max:32'],
            'instagram_handle' => ['nullable', 'string', 'max:255'],
            'wa_session_slug' => ['nullable', 'string', 'max:255', 'unique:artists,wa_session_slug'],
            'deposit_value' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        $slug = $data['wa_session_slug'] ?? null;

        if (! $slug) {
            $slug = Str::slug($data['name']);
            $slug = $slug !== '' ? $slug : 'artist';
            $base = $slug;
            $i = 2;
            while (Artist::where('wa_session_slug', $slug)->exists()) {
                $slug = $base.'-'.$i++;
            }
        }

        $artist = Artist::create([
            'name' => $data['name'],
            'status' => 'live',
            'timezone' => $data['timezone'] ?? 'America/Sao_Paulo',
            'wa_session_slug' => $slug,
        ]);

        $artist->settings()->create([
            'display_name' => $data['name'],
            'whatsapp_number' => $data['whatsapp_number'] ?? null,
            'instagram_handle' => $data['instagram_handle'] ?? null,
            'deposit_type' => 'percent',
            'deposit_value' => $data['deposit_value'] ?? 30,
            'working_hours' => $this->defaultWorkingHours(),
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            // Placeholder until the artist sets their own password via the invite.
            'password' => Hash::make(Str::random(64)),
            'role' => User::ROLE_ARTIST,
            'artist_id' => $artist->id,
        ]);

        $sent = $this->sendInvite($user);

        return redirect()
            ->route('artists.show', $artist)
            ->with('status', $sent
                ? 'Artista criado. Convite enviado para '.$user->email.'.'
                : 'Artista criado, mas o convite não pôde ser enviado. Use "Reenviar convite".');
    }

    /**
     * Staff: re-send the first-password invite.
     */
    public function resendInvite(Artist $artist)
    {
        $user = $artist->users()->where('role', User::ROLE_ARTIST)->latest()->first();

        if (! $user) {
            return back()->withErrors(['email' => 'Este artista não tem usuário de acesso.']);
        }

        return back()->with('status', $this->sendInvite($user)
            ? 'Convite reenviado para '.$user->email.'.'
            : 'Não foi possível enviar o convite. Verifique a configuração de e-mail.');
    }

    private function sendInvite(User $user): bool
    {
        try {
            $token = Password::broker()->createToken($user);
            Mail::to($user->email)->send(new ArtistInvite($user, $token));

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
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
