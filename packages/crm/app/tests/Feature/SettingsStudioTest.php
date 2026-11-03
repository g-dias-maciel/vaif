<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\ArtistSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SettingsStudioTest extends TestCase
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
            'name' => 'Artist Studio',
            'status' => 'live',
            'timezone' => 'America/Sao_Paulo',
            'wa_session_slug' => 'studio',
        ]);

        ArtistSetting::create([
            'artist_id' => $artist->id,
            'display_name' => 'Artist Studio',
        ]);

        return $artist;
    }

    private function user(Artist $artist): User
    {
        return User::create([
            'name' => 'Artist User',
            'email' => 'studio-'.$artist->id.'@vaif.test',
            'password' => Hash::make('password'),
            'role' => User::ROLE_ARTIST,
            'artist_id' => $artist->id,
        ]);
    }

    public function test_the_artist_can_save_the_studio_address(): void
    {
        $artist = $this->artist();
        $user = $this->user($artist);

        $this->actingAs($user)
            ->post('/config/studio', [
                'city' => 'Poços de Caldas',
                'address' => 'Rua das Flores, 123, Centro',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $settings = $artist->settings->refresh();

        $this->assertSame('Poços de Caldas', $settings->city);
        $this->assertSame('Rua das Flores, 123, Centro', $settings->address);

        $this->actingAs($user)->get('/config')
            ->assertOk()
            ->assertSee('Estúdio')
            ->assertSee('Poços de Caldas')
            ->assertSee('Rua das Flores, 123, Centro');
    }

    public function test_the_artist_can_clear_the_studio_address(): void
    {
        $artist = $this->artist();
        $user = $this->user($artist);

        $artist->settings->update([
            'city' => 'Poços de Caldas',
            'address' => 'Rua das Flores, 123',
        ]);

        $this->actingAs($user)
            ->post('/config/studio', ['city' => '', 'address' => ''])
            ->assertRedirect();

        $settings = $artist->settings->refresh();

        $this->assertNull($settings->city);
        $this->assertNull($settings->address);
    }
}
