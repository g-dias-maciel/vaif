<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ContactGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            'transport.crm_internal_token' => 'secret',
            'transport.waha_url' => 'https://waha.test',
            'transport.waha_api_key' => 'key',
        ]);
    }

    private function artist(): Artist
    {
        return Artist::create([
            'name' => 'Artist Gate',
            'status' => 'live',
            'timezone' => 'America/Sao_Paulo',
            'wa_session_slug' => 'gate',
        ]);
    }

    private function lead(Artist $artist, string $phone = '+5511900000000'): Lead
    {
        return Lead::create([
            'id' => (string) Str::uuid(),
            'artist_id' => $artist->id,
            'phone_e164' => $phone,
        ]);
    }

    private function ask(Artist $artist, Lead $lead)
    {
        return $this->withHeader('X-Crm-Token', 'secret')->postJson('/api/contact-gate', [
            'artist_id' => $artist->id,
            'lead_id' => $lead->id,
            'phone' => $lead->phone_e164,
        ]);
    }

    public function test_a_saved_contact_is_held_as_pending(): void
    {
        Http::fake([
            'waha.test/api/contacts/all*' => Http::response([
                ['id' => '5511900000000@c.us', 'phoneNumber' => '5511900000000@s.whatsapp.net', 'name' => 'Cliente Salvo'],
            ], 200),
        ]);

        $artist = $this->artist();
        $lead = $this->lead($artist);

        $this->ask($artist, $lead)
            ->assertOk()
            ->assertJson(['hold' => true, 'gate' => 'pending']);

        $this->assertSame('pending', $lead->refresh()->contact_gate);
    }

    public function test_an_unknown_number_is_not_held(): void
    {
        Http::fake([
            'waha.test/api/contacts/all*' => Http::response([
                ['id' => '5599999999999@c.us', 'phoneNumber' => '5599999999999@s.whatsapp.net', 'name' => 'Outra Pessoa'],
            ], 200),
        ]);

        $artist = $this->artist();
        $lead = $this->lead($artist);

        $this->ask($artist, $lead)->assertOk()->assertJson(['hold' => false]);

        $this->assertNull($lead->refresh()->contact_gate);
    }

    public function test_a_pushname_only_entry_is_not_a_saved_contact(): void
    {
        Http::fake([
            'waha.test/api/contacts/all*' => Http::response([
                ['id' => '5511900000000@c.us', 'phoneNumber' => '5511900000000@s.whatsapp.net', 'pushname' => 'Fulano'],
            ], 200),
        ]);

        $artist = $this->artist();
        $lead = $this->lead($artist);

        $this->ask($artist, $lead)->assertOk()->assertJson(['hold' => false]);

        $this->assertNull($lead->refresh()->contact_gate);
    }

    public function test_an_allowed_contact_is_not_held_again(): void
    {
        Http::fake();

        $artist = $this->artist();
        $lead = $this->lead($artist);
        $lead->forceFill(['contact_gate' => 'allowed'])->save();

        $this->ask($artist, $lead)->assertOk()->assertJson(['hold' => false]);

        Http::assertNothingSent();
    }

    public function test_a_waha_error_fails_open(): void
    {
        Http::fake(['waha.test/*' => Http::response('', 500)]);

        $artist = $this->artist();
        $lead = $this->lead($artist);

        $this->ask($artist, $lead)->assertOk()->assertJson(['hold' => false]);
        $this->assertNull($lead->refresh()->contact_gate);
    }

    public function test_a_missing_token_is_rejected(): void
    {
        $artist = $this->artist();
        $lead = $this->lead($artist);

        $this->postJson('/api/contact-gate', [
            'artist_id' => $artist->id,
            'lead_id' => $lead->id,
            'phone' => $lead->phone_e164,
        ])->assertUnauthorized();
    }
}
