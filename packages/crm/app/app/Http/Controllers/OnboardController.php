<?php

namespace App\Http\Controllers;

use App\Models\Artist;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class OnboardController extends Controller
{
    /**
     * Token-guarded webhook the n8n onboarding flow calls to provision a CRM
     * login (a `users` row) for a newly created Artist. No session auth.
     */
    public function login(Request $request)
    {
        $token = $request->bearerToken() ?: $request->input('token');
        $expected = (string) config('crm.onboard_webhook_token');

        if ($expected === '' || ! is_string($token) || ! hash_equals($expected, $token)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $data = $request->validate([
            'artist_id' => ['required', 'uuid'],
            'email' => ['required', 'email', 'max:255', 'not_regex:/[\r\n]/'],
            'password' => ['nullable', 'string', 'min:6'],
            'nome' => ['nullable', 'string', 'max:255'],
        ]);

        $artist = Artist::find($data['artist_id']);

        if (! $artist) {
            return response()->json(['error' => 'artist not found'], 404);
        }

        $user = User::firstOrCreate(
            ['email' => $data['email']],
            [
                'name' => $data['nome'] ?? $artist->displayName(),
                'password' => Hash::make($data['password'] ?? Str::random(16)),
                'role' => User::ROLE_ARTIST,
                'artist_id' => $artist->id,
            ]
        );

        // Keep the link/role correct even when the user already existed.
        if ($user->artist_id !== $artist->id || $user->role !== User::ROLE_ARTIST) {
            $user->update(['artist_id' => $artist->id, 'role' => User::ROLE_ARTIST]);
        }

        return response()->json(['ok' => true, 'user_id' => $user->id]);
    }
}
