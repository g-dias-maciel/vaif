<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Lead;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class ClientListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
    }

    private function artist(string $slug = 'clients'): Artist
    {
        return Artist::create([
            'name' => 'Artist '.$slug,
            'status' => 'live',
            'timezone' => 'America/Sao_Paulo',
            'wa_session_slug' => $slug,
        ]);
    }

    private function user(Artist $artist): User
    {
        return User::create([
            'name' => 'Artist User',
            'email' => 'clients-'.$artist->id.'@vaif.test',
            'password' => Hash::make('password'),
            'role' => User::ROLE_ARTIST,
            'artist_id' => $artist->id,
        ]);
    }

    private function lead(Artist $artist, string $name, string $phone, string $stage = 'qualificando'): Lead
    {
        $lead = Lead::create([
            'id' => (string) Str::uuid(),
            'artist_id' => $artist->id,
            'name' => $name,
            'phone_e164' => $phone,
            'last_message_at' => now(),
        ]);

        Project::create([
            'id' => (string) Str::uuid(),
            'artist_id' => $artist->id,
            'lead_id' => $lead->id,
            'stage_key' => $stage,
        ]);

        return $lead;
    }

    public function test_the_clients_tab_lists_clients_and_offers_live_search_and_filters(): void
    {
        $artist = $this->artist();
        $user = $this->user($artist);
        $this->lead($artist, 'João da Silva', '+5521999553136', 'agendado');
        $this->lead($artist, 'Maria Souza', '+5521988887777', 'qualificando');

        $this->actingAs($user)->get('/clients')
            ->assertOk()
            ->assertSee('Clientes — Artist clients')
            ->assertSee('João da Silva')
            ->assertSee('+5521999553136')
            ->assertSee('Maria Souza')
            ->assertSee('id="clients-search"', false)
            ->assertSee('id="clients-status"', false)
            ->assertSee('Agendado')
            ->assertSee('Qualificando')
            ->assertSee('data-client-search="joão da silva +5521999553136"', false)
            ->assertSee('js-delete-client', false)
            ->assertSee('delete-client-lightbox', false);
    }

    public function test_the_clients_tab_is_scoped_to_the_active_artist(): void
    {
        $artist = $this->artist();
        $other = $this->artist('other');
        $user = $this->user($artist);
        $this->lead($artist, 'Cliente Próprio', '+5511900000001');
        $this->lead($other, 'Cliente Alheio', '+5511900000002');

        $this->actingAs($user)->get('/clients')
            ->assertOk()
            ->assertSee('Cliente Próprio')
            ->assertDontSee('Cliente Alheio');
    }

    public function test_an_artist_cannot_delete_another_artists_client(): void
    {
        $owner = $this->artist('owner');
        $other = $this->artist('other');
        $lead = $this->lead($owner, 'Cliente do Owner', '+5511900000003');

        $this->actingAs($this->user($other))
            ->delete("/clients/{$lead->id}")
            ->assertForbidden();
    }
}
