<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\CalendarEntry;
use App\Models\Lead;
use App\Models\Payment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class DepositConfirmationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
    }

    private function artist(string $slug = 'deposit'): Artist
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
            'email' => 'deposit-'.$artist->id.'@vaif.test',
            'password' => Hash::make('password'),
            'role' => User::ROLE_ARTIST,
            'artist_id' => $artist->id,
        ]);
    }

    /**
     * @return array{0: Lead, 1: Project, 2: Payment}
     */
    private function pendingDeposit(Artist $artist): array
    {
        $lead = Lead::create([
            'id' => (string) Str::uuid(),
            'artist_id' => $artist->id,
            'name' => 'Cliente Sinal',
            'phone_e164' => '+5511900000111',
        ]);

        $project = Project::create([
            'id' => (string) Str::uuid(),
            'artist_id' => $artist->id,
            'lead_id' => $lead->id,
            'stage_key' => 'agendado',
            'placement' => 'braco',
            'body_zone' => 'medio',
        ]);

        $payment = Payment::create([
            'artist_id' => $artist->id,
            'project_id' => $project->id,
            'kind' => 'deposit',
            'amount_cents' => 5000,
            'status' => 'requested',
            'method' => 'pix',
            'requested_at' => now(),
        ]);

        return [$lead, $project, $payment];
    }

    public function test_the_dashboard_alerts_about_pending_deposits(): void
    {
        $artist = $this->artist();
        $user = $this->user($artist);
        [$lead] = $this->pendingDeposit($artist);

        $response = $this->actingAs($user)->get('/');

        $response->assertOk()
            ->assertSee('Sinais aguardando confirmação')
            ->assertSee($lead->displayName())
            ->assertSee('R$ 50,00')
            ->assertSee('Confirmar sinal')
            ->assertSee('id="dash-alerts"', false)
            ->assertSee('id="dash-updated"', false)
            ->assertSee('setInterval(refresh, 15000)', false);

        $this->assertSame(1, substr_count($response->getContent(), 'Sinais aguardando confirmação'));
    }

    public function test_the_lead_page_offers_tattoo_completion_after_a_booking(): void
    {
        $artist = $this->artist();
        $user = $this->user($artist);
        [$lead, $project] = $this->pendingDeposit($artist);

        CalendarEntry::create([
            'id' => (string) Str::uuid(),
            'artist_id' => $artist->id,
            'kind' => 'booking',
            'project_id' => $project->id,
            'lead_id' => $lead->id,
            'start_at' => now()->subHour(),
            'end_at' => now(),
            'active' => true,
            'created_by' => 'agent',
        ]);

        $this->actingAs($user)->get("/leads/{$lead->id}")
            ->assertOk()
            ->assertSee('Tattoo concluída');
    }

    public function test_the_lead_page_has_a_confirm_signal_button(): void
    {
        $artist = $this->artist();
        $user = $this->user($artist);
        [$lead] = $this->pendingDeposit($artist);

        $this->actingAs($user)->get("/leads/{$lead->id}")
            ->assertOk()
            ->assertSee('Confirmar sinal')
            ->assertSee('aguardando_confirmacao');
    }

    public function test_another_artist_cannot_confirm_the_signal(): void
    {
        $owner = $this->artist('owner');
        $other = $this->artist('other');
        [, $project] = $this->pendingDeposit($owner);

        $this->actingAs($this->user($other))
            ->post("/projects/{$project->id}/confirm-deposit")
            ->assertForbidden();
    }
}
