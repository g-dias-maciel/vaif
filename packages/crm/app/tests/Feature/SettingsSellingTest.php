<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\ArtistSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SettingsSellingTest extends TestCase
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
            'name' => 'Artist Selling',
            'status' => 'live',
            'timezone' => 'America/Sao_Paulo',
            'wa_session_slug' => 'selling',
        ]);

        ArtistSetting::create([
            'artist_id' => $artist->id,
            'display_name' => 'Artist Selling',
        ]);

        return $artist;
    }

    private function user(Artist $artist): User
    {
        return User::create([
            'name' => 'Artist User',
            'email' => 'selling-'.$artist->id.'@vaif.test',
            'password' => Hash::make('password'),
            'role' => User::ROLE_ARTIST,
            'artist_id' => $artist->id,
        ]);
    }

    public function test_full_selling_is_enabled_by_default(): void
    {
        $artist = $this->artist();
        $user = $this->user($artist);

        $this->assertTrue($artist->settings->refresh()->full_selling);

        $this->actingAs($user)->get('/config')
            ->assertOk()
            ->assertSee('Modo de atendimento')
            ->assertSee('Venda completa');
    }

    public function test_the_artist_can_switch_to_triage_only(): void
    {
        $artist = $this->artist();
        $user = $this->user($artist);

        $this->actingAs($user)
            ->post('/config/selling', ['full_selling' => '0'])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertFalse($artist->settings->refresh()->full_selling);

        $this->actingAs($user)->get('/config')
            ->assertOk()
            ->assertSee('id="full-selling"', false);
    }

    public function test_the_artist_can_switch_back_to_full_selling(): void
    {
        $artist = $this->artist();
        $user = $this->user($artist);

        $artist->settings->update(['full_selling' => false]);

        $this->actingAs($user)
            ->post('/config/selling', ['full_selling' => '1'])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertTrue($artist->settings->refresh()->full_selling);
    }

    public function test_staff_can_switch_the_mode_for_the_selected_artist(): void
    {
        $artist = $this->artist();
        $staff = User::create([
            'name' => 'Staff',
            'email' => 'staff-selling@vaif.test',
            'password' => Hash::make('password'),
            'role' => User::ROLE_STAFF,
        ]);

        $this->actingAs($staff)
            ->withSession(['staff_artist_id' => $artist->id])
            ->post('/config/selling', ['full_selling' => '0'])
            ->assertRedirect();

        $this->assertFalse($artist->settings->refresh()->full_selling);
    }

    public function test_the_mode_must_be_a_boolean(): void
    {
        $artist = $this->artist();
        $user = $this->user($artist);

        $this->actingAs($user)
            ->post('/config/selling', ['full_selling' => 'maybe'])
            ->assertSessionHasErrors('full_selling');

        $this->assertTrue($artist->settings->refresh()->full_selling);
    }
}
