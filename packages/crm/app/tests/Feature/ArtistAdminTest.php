<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\ArtistSetting;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ArtistAdminTest extends TestCase
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
            'name' => 'Artista Antigo',
            'status' => 'live',
            'timezone' => 'America/Sao_Paulo',
            'wa_session_slug' => 'artist-admin',
        ]);

        ArtistSetting::create([
            'artist_id' => $artist->id,
            'display_name' => 'Artista Antigo',
            'whatsapp_number' => '5511999999999',
            'deposit_type' => 'percent',
            'deposit_value' => 30,
        ]);

        return $artist;
    }

    private function staff(): User
    {
        return User::create([
            'name' => 'Staff',
            'email' => 'staff-'.Str::random(6).'@vaif.test',
            'password' => Hash::make('password'),
            'role' => User::ROLE_STAFF,
        ]);
    }

    private function artistUser(Artist $artist): User
    {
        return User::create([
            'name' => 'Login Antigo',
            'email' => 'artist-'.$artist->id.'@vaif.test',
            'password' => Hash::make('password'),
            'role' => User::ROLE_ARTIST,
            'artist_id' => $artist->id,
        ]);
    }

    public function test_staff_can_edit_artist_core_data_and_login(): void
    {
        $artist = $this->artist();
        $user = $this->artistUser($artist);
        $staff = $this->staff();

        $this->actingAs($staff)->get("/artists/{$artist->id}/edit")
            ->assertOk()
            ->assertSee('Artista Antigo');

        $this->actingAs($staff)->put("/artists/{$artist->id}", [
            'name' => 'Artista Novo',
            'email' => 'novo@vaif.test',
            'timezone' => 'America/Manaus',
            'whatsapp_number' => '(21) 98888-7777',
            'instagram_handle' => '@novo',
            'deposit_value' => 25,
            'status' => 'suspended',
        ])->assertRedirect("/artists/{$artist->id}");

        $artist->refresh();
        $this->assertSame('Artista Novo', $artist->name);
        $this->assertSame('America/Manaus', $artist->timezone);
        $this->assertSame('suspended', $artist->status);

        $settings = $artist->settings()->first();
        $this->assertSame('Artista Novo', $settings->display_name);
        $this->assertSame('21988887777', $settings->whatsapp_number);
        $this->assertSame('novo', $settings->instagram_handle);
        $this->assertSame('percent', $settings->deposit_type);
        $this->assertEquals(25, (float) $settings->deposit_value);

        $user->refresh();
        $this->assertSame('Artista Novo', $user->name);
        $this->assertSame('novo@vaif.test', $user->email);
    }

    public function test_artists_cannot_edit_or_delete_other_artists(): void
    {
        $artist = $this->artist();
        $user = $this->artistUser($artist);

        $this->actingAs($user)->get("/artists/{$artist->id}/edit")->assertForbidden();
        $this->actingAs($user)->delete("/artists/{$artist->id}")->assertForbidden();
    }

    public function test_staff_can_delete_an_artist_and_its_data(): void
    {
        Http::fake();

        $artist = $this->artist();
        $user = $this->artistUser($artist);
        Lead::create([
            'id' => (string) Str::uuid(),
            'artist_id' => $artist->id,
            'name' => 'Cliente do Artista',
            'phone_e164' => '+5511900000123',
        ]);

        $staff = $this->staff();

        $this->actingAs($staff)->delete("/artists/{$artist->id}")
            ->assertRedirect('/artists');

        $this->assertDatabaseMissing('artists', ['id' => $artist->id]);
        $this->assertDatabaseMissing('artist_settings', ['artist_id' => $artist->id]);
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('leads', ['artist_id' => $artist->id]);
    }
}
