<?php

namespace App\Http\Controllers;

use App\Models\Prospect;
use App\Models\TeamEvent;
use App\Models\User;
use App\Services\Google\GoogleCalendarService;
use App\Services\Google\TeamGoogleCalendarSync;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Staff-only team management: add/edit/remove internal users and assign the
 * `staff` (admin) or `sales` role. Artist logins stay in the Artists area.
 */
class TeamController extends Controller
{
    public function index()
    {
        $users = User::query()
            ->whereIn('role', array_keys(User::internalRoleOptions()))
            ->orderBy('name')
            ->get();

        return view('team.index', [
            'users' => $users,
            'staffCount' => $users->where('role', User::ROLE_STAFF)->count(),
            'salesCount' => $users->where('role', User::ROLE_SALES)->count(),
        ]);
    }

    public function create()
    {
        return view('team.form', [
            'user' => new User(['role' => User::ROLE_SALES]),
            'roles' => User::internalRoleOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);

        $data = $request->validate($this->rules(), $this->messages());

        User::create([
            'name' => trim((string) $data['name']),
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => $data['role'],
            'artist_id' => null,
        ]);

        return redirect()
            ->route('team.index')
            ->with('status', 'Usuário criado. Compartilhe a senha por um canal seguro.');
    }

    public function edit(User $user, GoogleCalendarService $google)
    {
        $this->ensureInternal($user);

        return view('team.form', [
            'user' => $user,
            'roles' => User::internalRoleOptions(),
            'googleConfigured' => $google->isConfigured(),
            'googleServiceEmail' => $google->serviceAccountEmail(),
            'googleCalendars' => session('google_calendars', []),
            'googleSyncErrors' => TeamEvent::query()
                ->where('owner_id', $user->id)
                ->whereNotNull('google_sync_error')
                ->count(),
            'googlePending' => TeamEvent::query()
                ->where('owner_id', $user->id)
                ->where('google_sync_pending', true)
                ->count(),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->ensureInternal($user);
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);

        $data = $request->validate($this->rules($user), $this->messages());

        if ($user->id === $request->user()?->id && $data['role'] !== User::ROLE_STAFF) {
            throw ValidationException::withMessages([
                'role' => 'Você não pode remover seu próprio acesso de administrador.',
            ]);
        }

        if ($user->isStaff() && $data['role'] !== User::ROLE_STAFF) {
            $otherStaff = User::query()
                ->where('role', User::ROLE_STAFF)
                ->where('id', '!=', $user->id)
                ->count();

            if ($otherStaff === 0) {
                throw ValidationException::withMessages([
                    'role' => 'Mantenha pelo menos um administrador na equipe.',
                ]);
            }
        }

        $attributes = [
            'name' => trim((string) $data['name']),
            'email' => $data['email'],
            'role' => $data['role'],
        ];

        if (filled($data['password'] ?? null)) {
            $attributes['password'] = $data['password'];
        }

        $user->update($attributes);

        return redirect()
            ->route('team.index')
            ->with('status', 'Usuário atualizado.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $this->ensureInternal($user);

        if ($user->id === $request->user()?->id) {
            return back()->withErrors(['user' => 'Você não pode excluir seu próprio usuário.']);
        }

        if ($user->isStaff()) {
            $otherStaff = User::query()
                ->where('role', User::ROLE_STAFF)
                ->where('id', '!=', $user->id)
                ->count();

            if ($otherStaff === 0) {
                return back()->withErrors(['user' => 'Mantenha pelo menos um administrador na equipe.']);
            }
        }

        // Keep the sales pipeline and calendar from pointing at a deleted user.
        Prospect::query()
            ->where('owner_id', $user->id)
            ->update(['owner_id' => $request->user()?->id]);

        TeamEvent::query()
            ->where('owner_id', $user->id)
            ->update([
                'owner_id' => $request->user()?->id,
                'google_sync_pending' => true,
            ]);

        $user->delete();

        return redirect()
            ->route('team.index')
            ->with('status', 'Usuário removido. Os prospects e compromissos dele foram transferidos para você.');
    }

    public function connectGoogle(
        Request $request,
        User $user,
        GoogleCalendarService $google,
    ): RedirectResponse {
        $this->ensureInternal($user);

        if (! $google->isConfigured()) {
            return back()->with('error', 'Google Agenda não configurada no servidor (GOOGLE_SERVICE_ACCOUNT_JSON).');
        }

        $data = $request->validate([
            'calendar_id' => ['required', 'string', 'max:255'],
        ]);

        $calendarId = trim($data['calendar_id']);

        try {
            $calendar = $google->getCalendar($calendarId);
        } catch (Throwable $e) {
            return back()->with('error', 'Não foi possível acessar essa agenda: '.$e->getMessage());
        }

        if (! $google->canWrite($calendarId)) {
            return back()->with('error', 'Essa agenda foi compartilhada apenas para leitura. Compartilhe com “Fazer alterações nos eventos”.');
        }

        $user->forceFill([
            'google_calendar_id' => $calendar['id'],
            'google_calendar_summary' => $calendar['summary'],
            'google_calendar_timezone' => $calendar['timezone'],
            'google_connected_at' => now(),
        ])->save();

        TeamEvent::query()
            ->where('owner_id', $user->id)
            ->update(['google_sync_pending' => true]);

        return back()->with('status', 'Google Agenda conectada. Os compromissos serão sincronizados em até 1 minuto.');
    }

    public function detectGoogleCalendars(
        User $user,
        GoogleCalendarService $google,
    ): RedirectResponse {
        $this->ensureInternal($user);

        if (! $google->isConfigured()) {
            return back()->with('error', 'Google Agenda não configurada no servidor (GOOGLE_SERVICE_ACCOUNT_JSON).');
        }

        try {
            $calendars = $google->listCalendars();
        } catch (Throwable $e) {
            return back()->with('error', 'Não foi possível listar as agendas: '.$e->getMessage());
        }

        if ($calendars === []) {
            return back()->with('error', 'Nenhuma agenda compartilhada com a conta de serviço. Compartilhe a agenda com '.$google->serviceAccountEmail().'.');
        }

        return back()->with('google_calendars', $calendars);
    }

    public function disconnectGoogle(User $user): RedirectResponse
    {
        $this->ensureInternal($user);

        $user->forceFill([
            'google_calendar_id' => null,
            'google_calendar_summary' => null,
            'google_calendar_timezone' => null,
            'google_connected_at' => null,
        ])->save();

        TeamEvent::query()
            ->where('owner_id', $user->id)
            ->update(['google_sync_pending' => true]);

        return back()->with('status', 'Google Agenda desconectada. Os eventos serão removidos da agenda antiga em até 1 minuto.');
    }

    public function syncGoogle(
        User $user,
        TeamGoogleCalendarSync $sync,
    ): RedirectResponse {
        $this->ensureInternal($user);

        if (! $user->hasGoogleCalendar()) {
            return back()->with('error', 'Conecte uma Google Agenda antes de sincronizar.');
        }

        TeamEvent::query()
            ->where('owner_id', $user->id)
            ->update(['google_sync_pending' => true]);

        $counts = $sync->syncUser($user);

        return back()->with(
            $counts['failed'] > 0 ? 'error' : 'status',
            sprintf(
                'Sincronização: %d criado(s), %d atualizado(s), %d removido(s), %d falha(s).',
                $counts['created'],
                $counts['updated'],
                $counts['deleted'],
                $counts['failed'],
            ),
        );
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(?User $user = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                'not_regex:/[\r\n]/',
                Rule::unique('users', 'email')->ignore($user?->id),
            ],
            'role' => ['required', Rule::in(array_keys(User::internalRoleOptions()))],
            'password' => [
                $user === null ? 'required' : 'nullable',
                'confirmed',
                Password::min(8),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'email.unique' => 'Já existe um usuário com este e-mail.',
            'password.confirmed' => 'A confirmação de senha não confere.',
            'password.min' => 'A senha precisa ter pelo menos 8 caracteres.',
        ];
    }

    private function ensureInternal(User $user): void
    {
        abort_unless($user->isInternal(), 404);
    }
}
