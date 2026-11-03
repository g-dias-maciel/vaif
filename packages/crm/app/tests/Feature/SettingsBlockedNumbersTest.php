<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\ArtistSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SettingsBlockedNumbersTest extends TestCase
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
            'name' => 'Artist Block',
            'status' => 'live',
            'timezone' => 'America/Sao_Paulo',
            'wa_session_slug' => 'block',
        ]);

        ArtistSetting::create([
            'artist_id' => $artist->id,
            'display_name' => 'Artist Block',
        ]);

        return $artist;
    }

    private function user(Artist $artist): User
    {
        return User::create([
            'name' => 'Artist User',
            'email' => 'block-'.$artist->id.'@vaif.test',
            'password' => Hash::make('password'),
            'role' => User::ROLE_ARTIST,
            'artist_id' => $artist->id,
        ]);
    }

    public function test_the_artist_can_save_normalized_blocked_numbers(): void
    {
        $artist = $this->artist();
        $user = $this->user($artist);

        $this->actingAs($user)
            ->post('/config/blocked-numbers', [
                'blocked_numbers' => '+55 (11) 99999-0001, 5511999990002; +55 11 99999-0001',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $settings = $artist->settings->refresh();

        $this->assertSame(['5511999990001', '5511999990002'], $settings->blocked_numbers);

        $this->actingAs($user)->get('/config')
            ->assertOk()
            ->assertSee('Números bloqueados')
            ->assertSee('5511999990001');
    }

    public function test_the_artist_can_clear_the_blocked_numbers(): void
    {
        $artist = $this->artist();
        $user = $this->user($artist);

        $artist->settings->update(['blocked_numbers' => ['5511999990001']]);

        $this->actingAs($user)
            ->post('/config/blocked-numbers', ['blocked_numbers' => ''])
            ->assertRedirect();

        $this->assertSame([], $artist->settings->refresh()->blocked_numbers);
    }

    public function test_too_short_numbers_are_rejected(): void
    {
        $artist = $this->artist();
        $user = $this->user($artist);

        $this->actingAs($user)
            ->post('/config/blocked-numbers', ['blocked_numbers' => '1234'])
            ->assertSessionHasErrors('blocked_numbers');

        $this->assertEmpty($artist->settings->refresh()->blocked_numbers);
    }
}
