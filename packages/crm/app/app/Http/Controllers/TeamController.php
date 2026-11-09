<?php

namespace App\Http\Controllers;

use App\Models\Prospect;
use App\Models\TeamEvent;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

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

    public function edit(User $user)
    {
        $this->ensureInternal($user);

        return view('team.form', [
            'user' => $user,
            'roles' => User::internalRoleOptions(),
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
            ->update(['owner_id' => $request->user()?->id]);

        $user->delete();

        return redirect()
            ->route('team.index')
            ->with('status', 'Usuário removido. Os prospects dele foram transferidos para você.');
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
