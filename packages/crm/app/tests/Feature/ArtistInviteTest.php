<?php

namespace Tests\Feature;

use App\Mail\ArtistInvite;
use App\Models\Artist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ArtistInviteTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        return User::create([
            'name' => 'VAIF Staff',
            'email' => 'staff@vaif.test',
            'password' => Hash::make('staff-password'),
            'role' => User::ROLE_STAFF,
        ]);
    }

    public function test_creating_an_artist_sends_a_password_invite_instead_of_asking_for_a_password(): void
    {
        Mail::fake();

        $response = $this->actingAs($this->staff())->post('/artists', [
            'name' => 'Novo Artista',
            'email' => 'artista@vaif.test',
        ]);

        $artist = Artist::where('name', 'Novo Artista')->firstOrFail();
        $user = User::where('email', 'artista@vaif.test')->firstOrFail();

        $response->assertRedirect(route('artists.show', $artist));
        $this->assertSame(User::ROLE_ARTIST, $user->role);
        $this->assertSame($artist->id, $user->artist_id);

        Mail::assertSent(ArtistInvite::class, function (ArtistInvite $mail) use ($user) {
            return $mail->hasTo($user->email);
        });

        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_the_invite_link_sets_the_password_and_consumes_the_token(): void
    {
        Mail::fake();

        $this->actingAs($this->staff())->post('/artists', [
            'name' => 'Artista Convite',
            'email' => 'convite@vaif.test',
        ]);

        $user = User::where('email', 'convite@vaif.test')->firstOrFail();
        $token = null;
        Mail::assertSent(ArtistInvite::class, function (ArtistInvite $mail) use (&$token) {
            $token = $mail->token;

            return true;
        });

        $this->assertNotNull($token);

        $this->post('/logout');

        $this->get(route('password.set', ['token' => $token, 'email' => $user->email]))
            ->assertOk()
            ->assertSee('Definir senha');

        $response = $this->post(route('password.set.store'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'minha-nova-senha',
            'password_confirmation' => 'minha-nova-senha',
        ]);

        $response->assertRedirect(route('login'));

        $user->refresh();
        $this->assertTrue(Hash::check('minha-nova-senha', $user->password));
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_an_invalid_invite_token_is_rejected(): void
    {
        User::create([
            'name' => 'Artista',
            'email' => 'invalido@vaif.test',
            'password' => Hash::make('x'),
            'role' => User::ROLE_ARTIST,
        ]);

        $response = $this->from(route('password.set', ['token' => 'bad-token']))
            ->post(route('password.set.store'), [
                'token' => 'bad-token',
                'email' => 'invalido@vaif.test',
                'password' => 'minha-nova-senha',
                'password_confirmation' => 'minha-nova-senha',
            ]);

        $response->assertSessionHasErrors('email');
    }
}
