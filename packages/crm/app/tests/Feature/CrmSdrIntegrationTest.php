<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\CalendarEntry;
use App\Models\Lead;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Promotion;
use App\Models\PromotionType;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * End-to-end test of the v2 shape: Leads (people) own Projects (jobs), and each
 * Project carries quotes, payments and calendar entries.
 *
 * Reads run on SQLite (local dev / CI default). The write paths that go through
 * Postgres RPCs (`mark lost`, `block`, `confirm deposit`, `cancel`, `reschedule`)
 * need the real functions and are skipped there — packages/crm/db/tests/v2_contract.sql
 * covers those RPCs on PostgreSQL.
 */
class CrmSdrIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const ARTIST_ID = 'b0000000-0000-0000-0000-000000000001';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);

        // Isolate from any real external HTTP (WAHA) — the CRM must never
        // make live network calls during these tests.
        Http::fake();
    }

    private function seedArtist(): Artist
    {
        $artist = Artist::create([
            'id' => self::ARTIST_ID,
            'name' => 'Bruno',
            'status' => 'live',
            'timezone' => 'America/Sao_Paulo',
            'wa_session_slug' => 'default',
        ]);

        $artist->settings()->create([
            'display_name' => 'Bruno',
            'working_hours' => [
                'seg' => ['09:00-12:00', '14:00-18:00'],
                'ter' => ['09:00-12:00', '14:00-18:00'],
                'qua' => ['09:00-12:00', '14:00-18:00'],
                'qui' => ['09:00-12:00', '14:00-18:00'],
                'sex' => ['09:00-12:00', '14:00-18:00'],
                'sab' => ['09:00-13:00'],
            ],
        ]);

        return $artist;
    }

    private function artistUser(): User
    {
        return User::create([
            'name' => 'Bruno',
            'email' => 'bruno@vaif.com.br',
            'password' => Hash::make('password'),
            'role' => 'artist',
            'artist_id' => self::ARTIST_ID,
        ]);
    }

    private function staffUser(): User
    {
        return User::create([
            'name' => 'VAIF Staff',
            'email' => 'admin@vaif.com.br',
            'password' => Hash::make('password'),
            'role' => 'staff',
        ]);
    }

    // --- SDR write simulation (what Beatriz/n8n would persist) ---

    private static int $phoneSeq = 0;

    private function sdrLead(array $attrs = []): Lead
    {
        self::$phoneSeq++;

        return Lead::create(array_merge([
            'id' => (string) Str::uuid(),
            'artist_id' => self::ARTIST_ID,
            'name' => 'Cliente',
            'phone_e164' => sprintf('551198888%04d', self::$phoneSeq),
            'source' => 'whatsapp',
        ], $attrs));
    }

    private function sdrProject(Lead $lead, array $attrs = []): Project
    {
        return Project::create(array_merge([
            'id' => (string) Str::uuid(),
            'artist_id' => self::ARTIST_ID,
            'lead_id' => $lead->id,
            'stage_key' => 'novo',
        ], $attrs));
    }

    private function sdrBooking(Project $project, $start = null): CalendarEntry
    {
        $start = $start ?? now()->addDay();

        return CalendarEntry::create([
            'artist_id' => self::ARTIST_ID,
            'kind' => 'booking',
            'project_id' => $project->id,
            'lead_id' => $project->lead_id,
            'start_at' => $start,
            'end_at' => $start->copy()->addMinutes(120),
            'active' => true,
        ]);
    }

    private function seedPromotion(array $attrs = []): Promotion
    {
        PromotionType::firstOrCreate(['key' => 'bogo_same_value'], [
            'name' => 'Leve 2 pelo valor de {multiplier}x',
            'description' => 'Compre 1 tatuagem e leve outra de mesmo valor ou menor.',
            'config_spec' => ['multiplier' => 2.0],
            'active' => true,
        ]);

        return Promotion::create(array_merge([
            'id' => (string) Str::uuid(),
            'artist_id' => self::ARTIST_ID,
            'type_key' => 'bogo_same_value',
            'name' => 'Leve 2',
            'multiplier' => 2.0,
            'audience' => 'all',
            'conditions' => [],
            'quote_valid_days' => 7,
            'status' => 'active',
            'activated_at' => now(),
        ], $attrs));
    }

    private function seedQuote(Project $project, Promotion $promotion, array $attrs = []): Quote
    {
        return Quote::create(array_merge([
            'id' => (string) Str::uuid(),
            'artist_id' => self::ARTIST_ID,
            'project_id' => $project->id,
            'kind' => 'promotion',
            'promotion_id' => $promotion->id,
            'base_price_cents' => 300000,
            'offered_price_cents' => 300000,
            'second_cap_cents' => 150000,
            'valid_until' => now()->addDays(6),
            'status' => 'open',
            'created_at' => now()->subDay(),
        ], $attrs));
    }

    private function seedPayment(Project $project, array $attrs = []): Payment
    {
        return Payment::create(array_merge([
            'id' => (string) Str::uuid(),
            'artist_id' => self::ARTIST_ID,
            'project_id' => $project->id,
            'kind' => 'deposit',
            'amount_cents' => 15000,
            'status' => 'requested',
            'requested_at' => now(),
        ], $attrs));
    }

    private function requiresPostgres(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires the Postgres RPCs (see packages/crm/db/tests/v2_contract.sql).');
        }
    }

    // --- Tests ---

    public function test_dashboard_shows_sdr_pipeline()
    {
        $this->seedArtist();
        $user = $this->artistUser();

        $this->sdrProject($this->sdrLead(['name' => 'Ana']));
        $this->sdrProject($this->sdrLead(['name' => 'Carlos']), ['stage_key' => 'qualificando', 'placement' => 'antebraco']);
        $this->sdrProject($this->sdrLead(['name' => 'Fernanda']), ['stage_key' => 'agendado', 'placement' => 'peito']);

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();
        $response->assertSee('Ana');
        $response->assertSee('Carlos');
        $response->assertSee('Fernanda');
        $response->assertSee('Novo');
        $response->assertSee('Qualificando');
        $response->assertSee('Agendado');
    }

    public function test_sdr_booking_appears_in_agenda_and_calendar()
    {
        $this->seedArtist();
        $user = $this->artistUser();

        $lead = $this->sdrLead(['name' => 'Rafael']);
        $project = $this->sdrProject($lead, [
            'stage_key' => 'agendado',
            'placement' => 'peito',
        ]);
        $this->seedPayment($project, ['status' => 'confirmed', 'confirmed_at' => now()]);
        $this->sdrBooking($project, now()->addDay()->setTime(10, 0));

        $agenda = $this->actingAs($user)->get('/bookings');
        $agenda->assertOk();
        $agenda->assertSee('Rafael');
        $agenda->assertSee('Sinal pago');

        $dashboard = $this->actingAs($user)->get('/');
        $dashboard->assertOk();
        $dashboard->assertSee('Rafael');
    }

    public function test_artist_marks_project_lost()
    {
        $this->requiresPostgres();
        $this->seedArtist();
        $user = $this->artistUser();

        $project = $this->sdrProject($this->sdrLead(['name' => 'Pedro']));

        $this->actingAs($user)->post("/projects/{$project->id}/lost")->assertRedirect();

        $this->assertSame('perdido', $project->fresh()->stage_key);
        $this->assertDatabaseHas('events', ['project_id' => $project->id, 'type' => 'stage_changed']);
    }

    public function test_artist_confirms_deposit()
    {
        $this->requiresPostgres();
        $this->seedArtist();
        $user = $this->artistUser();

        $lead = $this->sdrLead(['name' => 'Gustavo']);
        $project = $this->sdrProject($lead, ['stage_key' => 'aguardando_deposito']);
        $slot = $this->sdrBooking($project);
        $payment = $this->seedPayment($project);

        $this->actingAs($user)->post("/bookings/{$slot->id}/confirm-deposit")->assertRedirect();

        $this->assertSame('confirmed', $payment->fresh()->status);
        $this->assertDatabaseHas('returning_customers', [
            'artist_id' => self::ARTIST_ID,
            'phone_e164' => $lead->phone_e164,
        ]);
    }

    public function test_artist_cancels_booking()
    {
        $this->requiresPostgres();
        $this->seedArtist();
        $user = $this->artistUser();

        $project = $this->sdrProject($this->sdrLead(['name' => 'Fernanda']), ['stage_key' => 'agendado']);
        $slot = $this->sdrBooking($project);

        $this->actingAs($user)->post("/bookings/{$slot->id}/cancel")->assertRedirect();

        $this->assertFalse($slot->fresh()->active);
        $this->assertDatabaseHas('events', ['project_id' => $project->id, 'type' => 'booking_cancelled']);
    }

    public function test_artist_blocks_and_unblocks_time()
    {
        $this->requiresPostgres();
        $this->seedArtist();
        $user = $this->artistUser();

        $date = now()->addDays(5)->toDateString();

        $this->actingAs($user)->post('/bookings/block', ['date' => $date])->assertRedirect();

        $blocked = CalendarEntry::where('kind', 'block')->first();
        $this->assertNotNull($blocked);
        $this->assertSame($date, $blocked->start_at->toDateString());

        $this->actingAs($user)->post("/bookings/{$blocked->id}/unblock")->assertRedirect();

        $this->assertNull(CalendarEntry::find($blocked->id));
    }

    public function test_lead_detail_shows_project_and_contact()
    {
        $this->seedArtist();
        $user = $this->artistUser();

        $lead = $this->sdrLead([
            'name' => 'Ana',
            'phone_e164' => '5511988880001',
        ]);
        $project = $this->sdrProject($lead, [
            'stage_key' => 'fechado',
            'closed_at' => now(),
            'placement' => 'costas',
            'body_zone' => 'medio',
            'style' => 'realismo',
            'tattoo_type' => 'cover_up',
            'meaning' => 'Homenagem à mãe',
        ]);
        $this->seedQuote($project, $this->seedPromotion(), ['offered_price_cents' => 40000, 'second_cap_cents' => 20000]);

        $response = $this->actingAs($user)->get("/leads/{$lead->id}");

        $response->assertOk();
        $response->assertSee('Detalhes do projeto');
        $response->assertSee('Costas');
        $response->assertSee('realismo');
        $response->assertSee('Sim (cobertura)');
        $response->assertSee('Homenagem à mãe');
        $response->assertSee('R$ 400,00');
    }

    public function test_legacy_tattoo_url_redirects_to_lead()
    {
        $this->seedArtist();
        $user = $this->artistUser();
        $lead = $this->sdrLead(['name' => 'Ana']);

        $this->actingAs($user)->get("/tattoos/{$lead->id}")
            ->assertStatus(301)
            ->assertRedirect(route('leads.show', $lead));
    }

    public function test_analytics_counts_projects()
    {
        $this->seedArtist();
        $user = $this->artistUser();

        $won = $this->sdrProject($this->sdrLead(['name' => 'Ana']), ['stage_key' => 'fechado', 'closed_at' => now()]);
        $this->seedQuote($won, $this->seedPromotion(), ['offered_price_cents' => 40000, 'status' => 'accepted']);
        $this->sdrProject($this->sdrLead(['name' => 'Carlos']));

        $response = $this->actingAs($user)->get('/analytics?period=all');

        $response->assertOk();
        $response->assertViewHas('total', 2);
        $response->assertViewHas('won', 1);
        $response->assertViewHas('revenueCents', 40000);
    }

    public function test_faturamento_split()
    {
        $this->seedArtist();
        $user = $this->artistUser();
        $promotion = $this->seedPromotion();

        foreach ([['A', 'fechado', 60000], ['B', 'agendado', 50000], ['C', 'aguardando_deposito', 90000]] as [$name, $stage, $cents]) {
            $project = $this->sdrProject($this->sdrLead(['name' => $name]), [
                'stage_key' => $stage,
                'closed_at' => $stage === 'fechado' ? now() : null,
            ]);
            $this->seedQuote($project, $promotion, ['offered_price_cents' => $cents]);
        }

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();
        $response->assertViewHas('realizadoCents', 60000);
        $response->assertViewHas('agendadoCents', 50000);
        $response->assertViewHas('esperadoCents', 90000);
    }

    public function test_dashboard_pipeline_hides_old_closed_projects_by_default()
    {
        $this->seedArtist();
        $user = $this->artistUser();

        $oldClosed = $this->sdrProject($this->sdrLead(['name' => 'Antigo']), ['stage_key' => 'perdido']);
        Project::whereKey($oldClosed->id)->update(['closed_at' => now()->subMonths(10)]);

        $oldOpen = $this->sdrProject($this->sdrLead(['name' => 'Aberto']), ['stage_key' => 'qualificando']);
        Project::whereKey($oldOpen->id)->update(['updated_at' => now()->subMonths(10)]);

        $this->sdrProject($this->sdrLead(['name' => 'Recente']), ['stage_key' => 'fechado', 'closed_at' => now()]);

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();
        $response->assertDontSee('Antigo'); // old closed is outside the window
        $response->assertSee('Aberto');     // old open is always shown
        $response->assertSee('Recente');    // recently closed is inside the window
    }

    public function test_dashboard_pipeline_all_period_shows_closed_history()
    {
        $this->seedArtist();
        $user = $this->artistUser();

        $oldClosed = $this->sdrProject($this->sdrLead(['name' => 'Historico']), ['stage_key' => 'fechado']);
        Project::whereKey($oldClosed->id)->update(['closed_at' => now()->subMonths(10)]);

        $this->actingAs($user)->get('/')->assertDontSee('Historico');
        $this->actingAs($user)->get('/?period=all')->assertOk()->assertSee('Historico');
    }

    public function test_faturamento_follows_dashboard_period()
    {
        $this->seedArtist();
        $user = $this->artistUser();
        $promotion = $this->seedPromotion();

        $oldClosed = $this->sdrProject($this->sdrLead(['name' => 'Velho']), ['stage_key' => 'fechado']);
        Project::whereKey($oldClosed->id)->update(['closed_at' => now()->subMonths(10)]);
        $this->seedQuote($oldClosed, $promotion, ['offered_price_cents' => 60000]);

        $newClosed = $this->sdrProject($this->sdrLead(['name' => 'Novo']), ['stage_key' => 'fechado', 'closed_at' => now()]);
        $this->seedQuote($newClosed, $promotion, ['offered_price_cents' => 40000]);

        $this->actingAs($user)->get('/')
            ->assertViewHas('realizadoCents', 40000);

        $this->actingAs($user)->get('/?period=all')
            ->assertViewHas('realizadoCents', 100000);
    }

    public function test_dashboard_shows_active_promotion()
    {
        $this->seedArtist();
        $user = $this->artistUser();
        $this->seedPromotion(['name' => 'Leve 2 de Inverno']);

        $this->actingAs($user)->get('/')
            ->assertOk()
            ->assertSee('Promoção ativa')
            ->assertSee('Leve 2 de Inverno');
    }

    public function test_dashboard_shows_no_active_promotion()
    {
        $this->seedArtist();
        $user = $this->artistUser();

        $this->actingAs($user)->get('/')
            ->assertOk()
            ->assertSee('Sem promoção ativa');
    }

    public function test_config_lists_promotions_and_their_status()
    {
        $this->seedArtist();
        $user = $this->artistUser();
        $this->seedPromotion(['name' => 'Leve 2 de Inverno']);
        $this->seedPromotion(['name' => 'Promo Antiga', 'status' => 'ended', 'ended_at' => now()->subMonth()]);

        $this->actingAs($user)->get('/config')
            ->assertOk()
            ->assertSee('Leve 2 de Inverno')
            ->assertSee('Promo Antiga')
            ->assertSee('Ativa')
            ->assertSee('Encerrada');
    }

    public function test_lead_detail_shows_an_open_promotion_quote()
    {
        $this->seedArtist();
        $user = $this->artistUser();
        $promotion = $this->seedPromotion(['name' => 'Leve 2 de Inverno']);
        $lead = $this->sdrLead(['name' => 'Mariana']);
        $project = $this->sdrProject($lead, ['placement' => 'braco_externo', 'body_zone' => 'fechamento']);
        $this->seedQuote($project, $promotion);

        $this->actingAs($user)->get(route('leads.show', $lead))
            ->assertOk()
            ->assertSee('Cotações')
            ->assertSee('Leve 2 de Inverno')
            ->assertSee('R$ 3.000,00')
            ->assertSee('R$ 1.500,00')
            ->assertSee('Aberta');
    }

    public function test_lead_without_promotion_quotes_has_no_promotion_panel()
    {
        $this->seedArtist();
        $user = $this->artistUser();
        $lead = $this->sdrLead(['name' => 'Sem Promo']);
        $this->sdrProject($lead);

        $this->actingAs($user)->get(route('leads.show', $lead))
            ->assertOk()
            ->assertDontSee('Promoção:');
    }

    public function test_expired_promotion_quote_is_labelled_expired()
    {
        $this->seedArtist();
        $user = $this->artistUser();
        $promotion = $this->seedPromotion();
        $lead = $this->sdrLead();
        $project = $this->sdrProject($lead);
        $this->seedQuote($project, $promotion, [
            'created_at' => now()->subDays(10),
            'valid_until' => now()->subDays(3),
        ]);

        $this->actingAs($user)->get(route('leads.show', $lead))
            ->assertOk()
            ->assertSee('Expirada');
    }

    public function test_void_promotion_quote_is_labelled_annulled()
    {
        $this->seedArtist();
        $user = $this->artistUser();
        $promotion = $this->seedPromotion();
        $lead = $this->sdrLead();
        $project = $this->sdrProject($lead);
        $this->seedQuote($project, $promotion, ['status' => 'void']);

        $this->actingAs($user)->get(route('leads.show', $lead))
            ->assertOk()
            ->assertSee('Anulada');
    }

    public function test_lead_detail_shows_only_its_own_quote()
    {
        $this->seedArtist();
        $user = $this->artistUser();
        $promotion = $this->seedPromotion(['name' => 'Leve 2 de Inverno']);

        $matchingLead = $this->sdrLead(['name' => 'Com Promo']);
        $matching = $this->sdrProject($matchingLead, [
            'placement' => 'braco_externo',
            'body_zone' => 'fechamento',
            'stage_key' => 'orcamento_enviado',
        ]);

        $otherLead = $this->sdrLead(['name' => 'Sem Promo', 'phone_e164' => '5511977770002']);
        $this->sdrProject($otherLead, ['placement' => 'costas', 'body_zone' => 'medio']);

        $this->seedQuote($matching, $promotion);

        $this->actingAs($user)->get(route('leads.show', $matchingLead))
            ->assertOk()
            ->assertSee('Promoção: Leve 2 de Inverno')
            ->assertSee('R$ 3.000,00')
            ->assertSee('R$ 1.500,00');

        $this->actingAs($user)->get(route('leads.show', $otherLead))
            ->assertOk()
            ->assertDontSee('Promoção: Leve 2 de Inverno');
    }

    public function test_staff_sees_a_leads_promotion_quote()
    {
        $this->seedArtist();
        $promotion = $this->seedPromotion(['name' => 'Leve 2 de Inverno']);
        $lead = $this->sdrLead();
        $project = $this->sdrProject($lead);
        $this->seedQuote($project, $promotion);

        $this->actingAs($this->staffUser())->get(route('leads.show', $lead))
            ->assertOk()
            ->assertSee('Leve 2 de Inverno')
            ->assertSee('Aberta');
    }

    public function test_staff_can_toggle_agent()
    {
        $this->seedArtist();
        $staff = $this->staffUser();

        $this->actingAs($staff)->get('/artists')->assertOk()->assertSee('Bruno');

        $this->actingAs($staff)->post('/artists/'.self::ARTIST_ID.'/toggle')->assertRedirect();
        $this->assertSame('suspended', Artist::find(self::ARTIST_ID)->status);

        $this->actingAs($staff)->post('/artists/'.self::ARTIST_ID.'/toggle')->assertRedirect();
        $this->assertSame('live', Artist::find(self::ARTIST_ID)->status);
    }

    public function test_config_page_renders_price_table()
    {
        $this->seedArtist();
        $user = $this->artistUser();

        $response = $this->actingAs($user)->get('/config');

        $response->assertOk();
        $response->assertSee('Tabela de preços');
        $response->assertSee('Perna');
        $response->assertSee('Braço');
        $response->assertSee('Tronco');
    }

    public function test_config_saves_prices_as_versioned_rows()
    {
        $this->seedArtist();
        $user = $this->artistUser();

        $this->actingAs($user)->post('/pricing/bulk', [
            'region' => 'braco',
            'cells' => [
                'antebraco' => [
                    'pequeno' => ['price' => '300,00', 'duration' => 90],
                ],
            ],
        ])->assertRedirect();

        $this->assertDatabaseHas('price_book', [
            'artist_id' => self::ARTIST_ID,
            'placement' => 'antebraco',
            'body_zone' => 'pequeno',
            'base_price_cents' => 30000,
        ]);

        // A second save with a new price closes the old row and opens a new one.
        $this->actingAs($user)->post('/pricing/bulk', [
            'region' => 'braco',
            'cells' => [
                'antebraco' => [
                    'pequeno' => ['price' => '350,00', 'duration' => 90],
                ],
            ],
        ])->assertRedirect();

        $rows = DB::table('price_book')
            ->where('artist_id', self::ARTIST_ID)
            ->where('placement', 'antebraco')
            ->where('body_zone', 'pequeno')
            ->orderBy('effective_from')
            ->get();

        $this->assertCount(2, $rows);
        $this->assertNotNull($rows[0]->effective_to);
        $this->assertNull($rows[1]->effective_to);
        $this->assertSame(35000, (int) $rows[1]->base_price_cents);
    }

    public function test_staff_can_create_artist_with_login()
    {
        $staff = $this->staffUser();

        $this->actingAs($staff)->post('/artists', [
            'name' => 'Novo Artista',
            'email' => 'novo@vaif.com.br',
            'password' => 'password',
            'deposit_value' => 25,
        ])->assertRedirect();

        $this->assertDatabaseHas('artists', ['name' => 'Novo Artista']);
        $this->assertDatabaseHas('artist_settings', ['display_name' => 'Novo Artista', 'deposit_value' => 25]);
        $this->assertDatabaseHas('users', ['email' => 'novo@vaif.com.br', 'role' => 'artist']);
    }

    public function test_staff_can_select_artist_and_access_their_tabs()
    {
        $this->seedArtist();
        $staff = $this->staffUser();

        $this->actingAs($staff)->get('/artists/'.self::ARTIST_ID.'/select')->assertRedirect();

        $this->actingAs($staff)->get('/analytics')->assertOk()->assertSee('Análises');
        $this->actingAs($staff)->get('/config')->assertOk()->assertSee('Tabela de preços');
        $this->actingAs($staff)->get('/bookings')->assertOk();
    }

    public function test_onboard_webhook_provisions_login()
    {
        $this->seedArtist();
        config(['crm.onboard_webhook_token' => 'test-token']);

        $this->postJson('/api/onboard/login', [
            'artist_id' => self::ARTIST_ID,
            'email' => 'onboard@vaif.com.br',
            'password' => 'password',
        ], ['Authorization' => 'Bearer test-token'])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseHas('users', [
            'email' => 'onboard@vaif.com.br',
            'role' => 'artist',
            'artist_id' => self::ARTIST_ID,
        ]);
    }

    public function test_onboard_webhook_rejects_bad_token()
    {
        config(['crm.onboard_webhook_token' => 'test-token']);

        $this->postJson('/api/onboard/login', [
            'artist_id' => self::ARTIST_ID,
            'email' => 'x@vaif.com.br',
        ], ['Authorization' => 'Bearer wrong'])
            ->assertUnauthorized();
    }
}
