<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Promotion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PromotionAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
    }

    private function artist(): Artist
    {
        return Artist::create([
            'name' => 'Bruno',
            'status' => 'live',
            'timezone' => 'America/Sao_Paulo',
            'wa_session_slug' => 'default',
        ]);
    }

    private function staff(): User
    {
        return User::create([
            'name' => 'Staff',
            'email' => 'staff@vaif.test',
            'password' => Hash::make('staff-password'),
            'role' => User::ROLE_STAFF,
        ]);
    }

    public function test_staff_can_create_a_draft_promotion_for_an_artist(): void
    {
        $artist = $this->artist();

        $response = $this->actingAs($this->staff())->post('/promotions', [
            'artist_id' => $artist->id,
            'type_key' => 'bogo_same_value',
            'name' => 'Leve 2 de Inverno',
            'multiplier' => 2,
            'audience' => 'all',
            'quote_valid_days' => 7,
        ]);

        $promo = Promotion::where('name', 'Leve 2 de Inverno')->firstOrFail();

        $response->assertRedirect(route('promotions.edit', $promo));
        $this->assertSame($artist->id, $promo->artist_id);
        $this->assertSame('draft', $promo->status);
    }

    public function test_staff_can_edit_a_draft_promotion(): void
    {
        $artist = $this->artist();
        $promo = Promotion::create([
            'artist_id' => $artist->id,
            'type_key' => 'bogo_same_value',
            'name' => 'Antiga',
            'multiplier' => 2,
            'audience' => 'all',
            'quote_valid_days' => 7,
            'status' => 'draft',
        ]);

        $this->actingAs($this->staff())->put("/promotions/{$promo->id}", [
            'type_key' => 'bogo_same_value',
            'name' => 'Nova',
            'multiplier' => 2.5,
            'audience' => 'returning',
            'quote_valid_days' => 10,
        ])->assertRedirect(route('promotions.index'));

        $promo->refresh();
        $this->assertSame('Nova', $promo->name);
        $this->assertSame(2.5, $promo->multiplier);
        $this->assertSame('returning', $promo->audience);
        $this->assertSame(10, $promo->quote_valid_days);
    }

    public function test_the_multiplier_is_locked_while_a_promotion_is_active(): void
    {
        $artist = $this->artist();
        $promo = Promotion::create([
            'artist_id' => $artist->id,
            'type_key' => 'bogo_same_value',
            'name' => 'Ativa',
            'multiplier' => 2,
            'audience' => 'all',
            'quote_valid_days' => 7,
            'status' => 'active',
        ]);

        $this->actingAs($this->staff())->put("/promotions/{$promo->id}", [
            'type_key' => 'bogo_same_value',
            'name' => 'Ativa editada',
            'multiplier' => 3,
            'audience' => 'all',
            'quote_valid_days' => 7,
        ])->assertRedirect(route('promotions.index'));

        $promo->refresh();
        $this->assertSame('Ativa editada', $promo->name);
        $this->assertSame(2.0, $promo->multiplier);
    }

    public function test_artists_cannot_access_the_promotion_admin(): void
    {
        $artist = $this->artist();
        $user = User::create([
            'name' => 'Artist',
            'email' => 'artist@vaif.test',
            'password' => Hash::make('artist-password'),
            'role' => User::ROLE_ARTIST,
            'artist_id' => $artist->id,
        ]);

        $this->actingAs($user)->get('/promotions')->assertForbidden();
        $this->actingAs($user)->post('/promotions', [])->assertForbidden();
    }
}
