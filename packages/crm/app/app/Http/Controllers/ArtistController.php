<?php

namespace App\Http\Controllers;

use App\Models\Artist;
use App\Models\CalendarEntry;
use App\Models\ChatMessage;
use App\Models\Project;
use App\Models\User;
use App\Services\ArtistInviteService;
use App\Services\WahaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

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

        $lastMessageAt = ChatMessage::latestTimesForLeads(
            $projects->pluck('lead_id')->unique()->filter()->all()
        );

        return view('artists.show', [
            'artist' => $artist,
            'projects' => $projects,
            'lastMessageAt' => $lastMessageAt,
            'bookings' => $bookings,
            'pipeline' => config('crm.pipeline_statuses'),
        ]);
    }

    /**
     * Staff: edit an Artist's core data, login e-mail and signal percentage.
     */
    public function edit(Artist $artist)
    {
        $artist->loadMissing('settings');

        return view('artists.edit', [
            'artist' => $artist,
            'user' => $this->artistUser($artist),
        ]);
    }

    /**
     * Staff: persist the edited Artist data.
     */
    public function update(Request $request, Artist $artist)
    {
        $artist->loadMissing('settings');
        $user = $this->artistUser($artist);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'email', 'max:255', 'not_regex:/[\r\n]/',
                Rule::unique('users', 'email')->ignore($user?->id),
            ],
            'timezone' => ['nullable', 'string', 'max:64'],
            'whatsapp_number' => ['nullable', 'string', 'max:32'],
            'instagram_handle' => ['nullable', 'string', 'max:255'],
            'deposit_value' => ['nullable', 'numeric', 'min:1', 'max:100'],
            'status' => ['required', Rule::in(['live', 'onboarding', 'suspended'])],
        ]);

        $whatsapp = preg_replace('/\D+/', '', (string) ($data['whatsapp_number'] ?? ''));
        $instagram = ltrim(trim((string) ($data['instagram_handle'] ?? '')), '@');

        DB::transaction(function () use ($artist, $user, $data, $whatsapp, $instagram) {
            $artist->update([
                'name' => $data['name'],
                'timezone' => ($data['timezone'] ?? null) ?: 'America/Sao_Paulo',
                'status' => $data['status'],
            ]);

            $settings = $artist->settings()->firstOrCreate(['artist_id' => $artist->id]);
            $settings->update([
                'display_name' => $data['name'],
                'whatsapp_number' => $whatsapp !== '' ? $whatsapp : null,
                'instagram_handle' => $instagram !== '' ? $instagram : null,
                'deposit_type' => 'percent',
                'deposit_value' => ($data['deposit_value'] ?? null) !== null
                    ? (float) $data['deposit_value']
                    : $settings->deposit_value,
            ]);

            if ($user) {
                $user->update([
                    'name' => $data['name'],
                    'email' => $data['email'],
                ]);
            } else {
                $created = User::create([
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'password' => Hash::make(Str::random(64)),
                    'role' => User::ROLE_ARTIST,
                    'artist_id' => $artist->id,
                ]);

                $this->sendInvite($created);
            }
        });

        return redirect()
            ->route('artists.show', $artist)
            ->with('status', 'Dados do artista atualizados.');
    }

    /**
     * Staff: permanently delete an Artist and every record attached to them.
     */
    public function destroy(Artist $artist)
    {
        $wasSelected = (string) session('staff_artist_id') === (string) $artist->id;

        DB::transaction(function () use ($artist) {
            // Remove the login(s) first; the artist rows cascade from `artists`.
            User::query()
                ->where('artist_id', $artist->id)
                ->where('role', User::ROLE_ARTIST)
                ->delete();

            $artist->delete();
        });

        if ($wasSelected) {
            session()->forget('staff_artist_id');
        }

        // Best effort: stop the WhatsApp session; deleting the CRM data must
        // still succeed if WAHA is unreachable.
        WahaService::make()->deleteSession($artist);

        return redirect()
            ->route('artists.index')
            ->with('status', 'Artista excluído. Todos os leads, projetos e conversas foram removidos.');
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
            'email' => ['required', 'email', 'max:255', 'not_regex:/[\r\n]/', 'unique:users,email'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'whatsapp_number' => ['nullable', 'string', 'max:32'],
            'instagram_handle' => ['nullable', 'string', 'max:255'],
            'wa_session_slug' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9][a-z0-9-]*$/', 'unique:artists,wa_session_slug'],
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
            ->with($sent ? 'status' : 'error', $sent
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
            return back()->with('error', 'Este artista não tem usuário de acesso.');
        }

        $sent = $this->sendInvite($user);

        return back()->with($sent ? 'status' : 'error', $sent
            ? 'Convite reenviado para '.$user->email.'.'
            : 'Não foi possível enviar o convite. Verifique a configuração de e-mail.');
    }

    private function artistUser(Artist $artist): ?User
    {
        return $artist->users()
            ->where('role', User::ROLE_ARTIST)
            ->latest()
            ->first();
    }

    private function sendInvite(User $user): bool
    {
        return app(ArtistInviteService::class)->send($user);
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
