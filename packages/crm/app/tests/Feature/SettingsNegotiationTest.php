<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\ArtistSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SettingsNegotiationTest extends TestCase
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
            'name' => 'Artist Negotiation',
            'status' => 'live',
            'timezone' => 'America/Sao_Paulo',
            'wa_session_slug' => 'negotiation',
        ]);

        ArtistSetting::create([
            'artist_id' => $artist->id,
            'display_name' => 'Artist Negotiation',
        ]);

        return $artist;
    }

    private function user(Artist $artist): User
    {
        return User::create([
            'name' => 'Artist User',
            'email' => 'negotiation-'.$artist->id.'@vaif.test',
            'password' => Hash::make('password'),
            'role' => User::ROLE_ARTIST,
            'artist_id' => $artist->id,
        ]);
    }

    public function test_the_artist_can_set_a_maximum_discount(): void
    {
        $artist = $this->artist();
        $user = $this->user($artist);

        $this->actingAs($user)
            ->post('/config/negotiation', ['max_discount' => 10])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame(90.0, (float) $artist->settings->refresh()->floor_pct);

        $this->actingAs($user)->get('/config')
            ->assertOk()
            ->assertSee('Desconto máximo (%)')
            ->assertSee('value="10.00"', false);
    }

    public function test_the_discount_must_be_between_zero_and_one_hundred(): void
    {
        $artist = $this->artist();
        $user = $this->user($artist);

        $this->actingAs($user)
            ->post('/config/negotiation', ['max_discount' => 101])
            ->assertSessionHasErrors('max_discount');

        $this->assertNull($artist->settings->refresh()->floor_pct);
    }
}
