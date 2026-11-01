<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\ArtistSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SettingsStylesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
    }

    private function artist(): Artist
    {
        $artist = Artist::create([
            'name' => 'Artist Styles',
            'status' => 'live',
            'timezone' => 'America/Sao_Paulo',
            'wa_session_slug' => 'styles',
        ]);

        ArtistSetting::create([
            'artist_id' => $artist->id,
            'display_name' => 'Artist Styles',
        ]);

        return $artist;
    }

    private function user(Artist $artist): User
    {
        return User::create([
            'name' => 'Artist User',
            'email' => 'styles-'.$artist->id.'@vaif.test',
            'password' => Hash::make('password'),
            'role' => User::ROLE_ARTIST,
            'artist_id' => $artist->id,
        ]);
    }

    public function test_the_artist_can_save_the_styles_they_do_and_do_not_do(): void
    {
        $artist = $this->artist();
        $user = $this->user($artist);

        $this->actingAs($user)
            ->post('/config/styles', [
                'specialties' => 'realismo, blackwork',
                'excluded_styles' => 'rosto, partes íntimas',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $settings = $artist->settings->refresh();

        $this->assertSame(['realismo', 'blackwork'], $settings->specialties);
        $this->assertSame(['rosto', 'partes íntimas'], $settings->excluded_styles);

        $this->actingAs($user)->get('/config')
            ->assertOk()
            ->assertSee('Estilos que faço')
            ->assertSee('Estilos que não faço')
            ->assertSee('name="specialties"', false)
            ->assertSee('value="realismo,blackwork"', false)
            ->assertSee('value="rosto,partes íntimas"', false);
    }

    public function test_styles_are_trimmed_and_de_duplicated_case_insensitively(): void
    {
        $artist = $this->artist();
        $user = $this->user($artist);

        $this->actingAs($user)
            ->post('/config/styles', [
                'specialties' => '  Realismo , blackwork,, REALISMO ; aquarela ',
                'excluded_styles' => '',
            ])
            ->assertRedirect();

        $settings = $artist->settings->refresh();

        $this->assertSame(['Realismo', 'blackwork', 'aquarela'], $settings->specialties);
        $this->assertSame([], $settings->excluded_styles);
    }

    public function test_the_artist_can_clear_both_lists(): void
    {
        $artist = $this->artist();
        $user = $this->user($artist);

        $artist->settings->update([
            'specialties' => ['realismo'],
            'excluded_styles' => ['rosto'],
        ]);

        $this->actingAs($user)
            ->post('/config/styles', [
                'specialties' => '',
                'excluded_styles' => '',
            ])
            ->assertRedirect();

        $settings = $artist->settings->refresh();

        $this->assertSame([], $settings->specialties);
        $this->assertSame([], $settings->excluded_styles);
    }

    public function test_a_style_cannot_be_in_both_lists(): void
    {
        $artist = $this->artist();
        $user = $this->user($artist);

        $this->actingAs($user)
            ->post('/config/styles', [
                'specialties' => 'Realismo, blackwork',
                'excluded_styles' => 'realismo',
            ])
            ->assertSessionHasErrors('excluded_styles');

        $settings = $artist->settings->refresh();

        $this->assertEmpty($settings->specialties);
        $this->assertEmpty($settings->excluded_styles);
    }

    public function test_the_lists_are_limited_to_thirty_styles_and_forty_characters_each(): void
    {
        $artist = $this->artist();
        $user = $this->user($artist);

        $tooMany = implode(',', array_map(fn (int $i) => 'estilo '.$i, range(1, 31)));

        $this->actingAs($user)
            ->post('/config/styles', ['specialties' => $tooMany])
            ->assertSessionHasErrors('specialties');

        $this->actingAs($user)
            ->post('/config/styles', ['excluded_styles' => str_repeat('a', 41)])
            ->assertSessionHasErrors('excluded_styles');
    }

    public function test_staff_can_save_styles_for_the_selected_artist(): void
    {
        $artist = $this->artist();
        $staff = User::create([
            'name' => 'Staff',
            'email' => 'staff@vaif.test',
            'password' => Hash::make('password'),
            'role' => User::ROLE_STAFF,
        ]);

        $this->actingAs($staff)
            ->withSession(['staff_artist_id' => $artist->id])
            ->post('/config/styles', [
                'specialties' => 'fine line',
                'excluded_styles' => 'blackwork',
            ])
            ->assertRedirect();

        $settings = $artist->settings->refresh();

        $this->assertSame(['fine line'], $settings->specialties);
        $this->assertSame(['blackwork'], $settings->excluded_styles);
    }
}
