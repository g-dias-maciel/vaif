<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\ArtistSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SettingsPaymentTest extends TestCase
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
            'name' => 'Artist Pix',
            'status' => 'live',
            'timezone' => 'America/Sao_Paulo',
            'wa_session_slug' => 'pix-test',
        ]);

        ArtistSetting::create([
            'artist_id' => $artist->id,
            'display_name' => 'Artist Pix',
        ]);

        return $artist;
    }

    private function artistUser(Artist $artist): User
    {
        return User::create([
            'name' => 'Artist User',
            'email' => 'pix-'.$artist->id.'@vaif.test',
            'password' => Hash::make('password'),
            'role' => User::ROLE_ARTIST,
            'artist_id' => $artist->id,
        ]);
    }

    public function test_an_artist_can_edit_the_signal_percentage(): void
    {
        $artist = $this->artist();
        $user = $this->artistUser($artist);

        $this->actingAs($user)
            ->post('/config/payment', [
                'pix_key' => 'artist@example.com',
                'deposit_percentage' => 25,
            ])
            ->assertRedirect();

        $settings = $artist->settings->refresh();

        $this->assertSame('percent', $settings->deposit_type);
        $this->assertEquals(25, (float) $settings->deposit_value);
        $this->assertSame('artist@example.com', $settings->pix_key);
    }

    public function test_an_artist_can_save_and_clear_the_pix_key(): void
    {
        $artist = $this->artist();
        $user = $this->artistUser($artist);

        $this->actingAs($user)
            ->post('/config/payment', ['pix_key' => '  artist@example.com  '])
            ->assertRedirect();

        $this->assertSame('artist@example.com', $artist->settings->refresh()->pix_key);

        $this->actingAs($user)
            ->post('/config/payment', ['pix_key' => ''])
            ->assertRedirect();

        $this->assertNull($artist->settings->refresh()->pix_key);
    }
}
