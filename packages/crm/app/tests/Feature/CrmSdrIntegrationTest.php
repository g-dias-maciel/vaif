<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\CalendarSlot;
use App\Models\Lead;
use App\Models\Promotion;
use App\Models\PromotionQuote;
use App\Models\PromotionType;
use App\Models\Tattoo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * End-to-end integration test: simulates the SDR agent's writes (the exact
 * shapes n8n/Beatriz produce) and drives the Artist-facing CRM through HTTP,
 * verifying the two work together end-to-end.
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
        return Artist::create([
            'id' => self::ARTIST_ID,
            'nome' => 'Bruno',
            'display_name' => 'Bruno',
            'status' => 'live',
            'timezone' => 'America/Sao_Paulo',
            'wa_session_slug' => 'default',
            'working_hours' => [
                'seg' => ['09:00-12:00', '14:00-18:00'],
                'ter' => ['09:00-12:00', '14:00-18:00'],
                'qua' => ['09:00-12:00', '14:00-18:00'],
                'qui' => ['09:00-12:00', '14:00-18:00'],
                'sex' => ['09:00-12:00', '14:00-18:00'],
                'sab' => ['09:00-13:00'],
            ],
        ]);
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

    private function sdrLead(array $attrs = []): Lead
    {
        return Lead::create(array_merge([
            'id' => (string) Str::uuid(),
            'artist_id' => self::ARTIST_ID,
            'nome' => 'Cliente',
            'telefone' => '5511988880001',
        ], $attrs));
    }

    private function sdrTattoo(Lead $lead, array $attrs = []): Tattoo
    {
        return Tattoo::create(array_merge([
            'lead_id' => $lead->id,
            'artist_id' => self::ARTIST_ID,
            'pipeline_status' => 'novo',
        ], $attrs));
    }

    private function sdrBooking(Tattoo $tattoo, $start = null): CalendarSlot
    {
        $start = $start ?? now()->addDay();

        return CalendarSlot::create([
            'artist_id' => self::ARTIST_ID,
            'lead_id' => $tattoo->lead_id,
            'tattoo_id' => $tattoo->id,
            'type' => 'booked',
            'start_at' => $start,
            'end_at' => $start->copy()->addMinutes(120),
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

    private function seedQuote(Lead $lead, Promotion $promotion, array $attrs = []): PromotionQuote
    {
        return PromotionQuote::create(array_merge([
            'id' => (string) Str::uuid(),
            'promotion_id' => $promotion->id,
            'lead_id' => $lead->id,
            'artist_id' => self::ARTIST_ID,
            'placement' => 'braco_externo',
            'body_zone' => 'fechamento',
            'first_price' => 300000,
            'second_cap_price' => 150000,
            'quoted_at' => now()->subDay(),
            'valid_until' => now()->addDays(6),
            'status' => 'open',
        ], $attrs));
    }

    // --- Tests ---

    public function test_dashboard_shows_sdr_pipeline()
    {
        $this->seedArtist();
        $user = $this->artistUser();

        $l1 = $this->sdrLead(['nome' => 'Ana']);
        $this->sdrTattoo($l1, ['pipeline_status' => 'novo']);

        $l2 = $this->sdrLead(['nome' => 'Carlos']);
        $this->sdrTattoo($l2, ['pipeline_status' => 'qualificando', 'placement' => 'antebraco']);

        $l3 = $this->sdrLead(['nome' => 'Fernanda']);
        $this->sdrTattoo($l3, ['pipeline_status' => 'agendado', 'booked_date' => now()->addDay()]);

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

        $lead = $this->sdrLead(['nome' => 'Rafael']);
        $tattoo = $this->sdrTattoo($lead, [
            'pipeline_status' => 'agendado',
            'placement' => 'peito',
            'deposit_status' => 'confirmado',
            'booked_date' => now()->addDay()->setTime(10, 0),
        ]);
        $this->sdrBooking($tattoo, now()->addDay()->setTime(10, 0));

        $agenda = $this->actingAs($user)->get('/bookings');
        $agenda->assertOk();
        $agenda->assertSee('Rafael');
        $agenda->assertSee('Sinal pago');

        $dashboard = $this->actingAs($user)->get('/');
        $dashboard->assertOk();
        $dashboard->assertSee('Rafael');
    }

    public function test_artist_marks_tattoo_lost()
    {
        $this->seedArtist();
        $user = $this->artistUser();

        $lead = $this->sdrLead(['nome' => 'Pedro']);
        $tattoo = $this->sdrTattoo($lead, ['pipeline_status' => 'novo']);

        $this->actingAs($user)->post("/tattoos/{$tattoo->id}/lost")->assertRedirect();

        $this->assertSame('perdido', $tattoo->fresh()->pipeline_status);
        $this->assertDatabaseHas('events', ['tattoo_id' => $tattoo->id, 'event_type' => 'tattoo_lost']);
    }

    public function test_artist_confirms_deposit()
    {
        $this->seedArtist();
        $user = $this->artistUser();

        $lead = $this->sdrLead(['nome' => 'Gustavo']);
        $tattoo = $this->sdrTattoo($lead, [
            'pipeline_status' => 'agendado',
            'deposit_status' => 'aguardando_confirmacao',
        ]);
        $slot = $this->sdrBooking($tattoo);

        $this->actingAs($user)->post("/bookings/{$slot->id}/confirm-deposit")->assertRedirect();

        $this->assertSame('confirmado', $tattoo->fresh()->deposit_status);
        $this->assertDatabaseHas('events', ['tattoo_id' => $tattoo->id, 'event_type' => 'deposit_confirmed']);
    }

    public function test_artist_cancels_booking()
    {
        $this->seedArtist();
        $user = $this->artistUser();

        $lead = $this->sdrLead(['nome' => 'Fernanda']);
        $tattoo = $this->sdrTattoo($lead, ['pipeline_status' => 'agendado', 'booked_date' => now()->addDay()]);
        $slot = $this->sdrBooking($tattoo);

        $this->actingAs($user)->post("/bookings/{$slot->id}/cancel")->assertRedirect();

        $this->assertNull(CalendarSlot::find($slot->id));
        $this->assertSame('aguardando_deposito', $tattoo->fresh()->pipeline_status);
        $this->assertNull($tattoo->fresh()->booked_date);
    }

    public function test_artist_blocks_and_unblocks_time()
    {
        $this->seedArtist();
        $user = $this->artistUser();

        $date = now()->addDays(5)->toDateString();

        $this->actingAs($user)->post('/bookings/block', ['date' => $date])->assertRedirect();

        $blocked = CalendarSlot::where('type', 'blocked')->first();
        $this->assertNotNull($blocked);
        $this->assertSame($date, $blocked->start_at->toDateString());

        $this->actingAs($user)->post("/bookings/{$blocked->id}/unblock")->assertRedirect();

        $this->assertNull(CalendarSlot::find($blocked->id));
    }

    public function test_lead_can_have_multiple_tattoos()
    {
        $this->seedArtist();
        $user = $this->artistUser();

        $lead = $this->sdrLead(['nome' => 'Ana']);
        $this->sdrTattoo($lead, ['pipeline_status' => 'fechado', 'placement' => 'costas', 'table_price' => 40000]);
        $this->sdrTattoo($lead, ['pipeline_status' => 'novo']);

        $response = $this->actingAs($user)->get("/leads/{$lead->id}");

        $response->assertOk();
        $response->assertSee('Tatuagens (2)');
    }

    public function test_analytics_counts_leads_not_tattoos()
    {
        $this->seedArtist();
        $user = $this->artistUser();

        $lead1 = $this->sdrLead(['nome' => 'Ana']);
        $this->sdrTattoo($lead1, ['pipeline_status' => 'fechado', 'table_price' => 40000]);
        $this->sdrTattoo($lead1, ['pipeline_status' => 'novo']);

        $lead2 = $this->sdrLead(['nome' => 'Carlos']);
        $this->sdrTattoo($lead2, ['pipeline_status' => 'novo']);

        $response = $this->actingAs($user)->get('/analytics?period=all');

        $response->assertOk();
        $response->assertViewHas('total', 2); // 2 leads, not 3 tattoos
        $response->assertViewHas('won', 1);   // only Ana has a won tattoo
    }

    public function test_faturamento_split()
    {
        $this->seedArtist();
        $user = $this->artistUser();

        $this->sdrTattoo($this->sdrLead(['nome' => 'A']), ['pipeline_status' => 'fechado', 'table_price' => 60000]);
        $this->sdrTattoo($this->sdrLead(['nome' => 'B']), ['pipeline_status' => 'agendado', 'table_price' => 50000]);
        $this->sdrTattoo($this->sdrLead(['nome' => 'C']), ['pipeline_status' => 'aguardando_deposito', 'table_price' => 90000]);

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

        $oldClosed = $this->sdrTattoo($this->sdrLead(['nome' => 'Antigo']), [
            'pipeline_status' => 'perdido',
            'table_price' => 50000,
        ]);
        Tattoo::whereKey($oldClosed->id)->update(['updated_at' => now()->subMonths(10)]);

        $oldOpen = $this->sdrTattoo($this->sdrLead(['nome' => 'Aberto']), [
            'pipeline_status' => 'qualificando',
            'table_price' => 30000,
        ]);
        Tattoo::whereKey($oldOpen->id)->update(['updated_at' => now()->subMonths(10)]);

        $this->sdrTattoo($this->sdrLead(['nome' => 'Recente']), [
            'pipeline_status' => 'fechado',
            'table_price' => 40000,
        ]);

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

        $oldClosed = $this->sdrTattoo($this->sdrLead(['nome' => 'Historico']), [
            'pipeline_status' => 'fechado',
            'table_price' => 50000,
        ]);
        Tattoo::whereKey($oldClosed->id)->update(['updated_at' => now()->subMonths(10)]);

        $this->actingAs($user)->get('/')->assertDontSee('Historico');
        $this->actingAs($user)->get('/?period=all')->assertOk()->assertSee('Historico');
    }

    public function test_faturamento_follows_dashboard_period()
    {
        $this->seedArtist();
        $user = $this->artistUser();

        $oldClosed = $this->sdrTattoo($this->sdrLead(['nome' => 'Velho']), [
            'pipeline_status' => 'fechado',
            'table_price' => 60000,
        ]);
        Tattoo::whereKey($oldClosed->id)->update(['updated_at' => now()->subMonths(10)]);

        $this->sdrTattoo($this->sdrLead(['nome' => 'Novo']), [
            'pipeline_status' => 'fechado',
            'table_price' => 40000,
        ]);

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
        $lead = $this->sdrLead(['nome' => 'Mariana']);
        $this->seedQuote($lead, $promotion);

        $this->actingAs($user)->get(route('leads.show', $lead))
            ->assertOk()
            ->assertSee('Promoções')
            ->assertSee('Leve 2 de Inverno')
            ->assertSee('R$ 3.000,00')
            ->assertSee('R$ 1.500,00')
            ->assertSee('Aberta');
    }

    public function test_lead_without_promotion_quotes_has_no_promotion_panel()
    {
        $this->seedArtist();
        $user = $this->artistUser();
        $lead = $this->sdrLead(['nome' => 'Sem Promo']);

        $this->actingAs($user)->get(route('leads.show', $lead))
            ->assertOk()
            ->assertDontSee('Cotações de promoção enviadas');
    }

    public function test_expired_promotion_quote_is_labelled_expired()
    {
        $this->seedArtist();
        $user = $this->artistUser();
        $promotion = $this->seedPromotion();
        $lead = $this->sdrLead();
        $this->seedQuote($lead, $promotion, [
            'quoted_at' => now()->subDays(10),
            'valid_until' => now()->subDays(3),
        ]);

        $this->actingAs($user)->get(route('leads.show', $lead))
            ->assertOk()
            ->assertSee('Expirada')
            ->assertDontSee('>Aberta<', false);
    }

    public function test_void_promotion_quote_is_labelled_annulled()
    {
        $this->seedArtist();
        $user = $this->artistUser();
        $promotion = $this->seedPromotion();
        $lead = $this->sdrLead();
        $this->seedQuote($lead, $promotion, ['status' => 'void']);

        $this->actingAs($user)->get(route('leads.show', $lead))
            ->assertOk()
            ->assertSee('Anulada');
    }

    public function test_tattoo_detail_shows_only_the_matching_promotion_quote()
    {
        $this->seedArtist();
        $user = $this->artistUser();
        $promotion = $this->seedPromotion(['name' => 'Leve 2 de Inverno']);
        $lead = $this->sdrLead();

        $matching = $this->sdrTattoo($lead, [
            'placement' => 'braco_externo',
            'body_zone' => 'fechamento',
            'pipeline_status' => 'orcamento_enviado',
        ]);
        $other = $this->sdrTattoo($lead, [
            'placement' => 'costas',
            'body_zone' => 'medio',
            'pipeline_status' => 'novo',
        ]);

        $this->seedQuote($lead, $promotion);

        $this->actingAs($user)->get(route('tattoos.show', $matching))
            ->assertOk()
            ->assertSee('Promoção: Leve 2 de Inverno')
            ->assertSee('R$ 3.000,00')
            ->assertSee('R$ 1.500,00');

        $this->actingAs($user)->get(route('tattoos.show', $other))
            ->assertOk()
            ->assertDontSee('Promoção: Leve 2 de Inverno');
    }

    public function test_staff_sees_a_leads_promotion_quote()
    {
        $this->seedArtist();
        $promotion = $this->seedPromotion(['name' => 'Leve 2 de Inverno']);
        $lead = $this->sdrLead();
        $this->seedQuote($lead, $promotion);

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

    public function test_staff_can_mark_tattoo_lost_on_any_artist()
    {
        $this->seedArtist();
        $staff = $this->staffUser();

        $lead = $this->sdrLead(['nome' => 'Pedro']);
        $tattoo = $this->sdrTattoo($lead, ['pipeline_status' => 'novo']);

        $this->actingAs($staff)->post("/tattoos/{$tattoo->id}/lost")->assertRedirect();

        $this->assertSame('perdido', $tattoo->fresh()->pipeline_status);
    }

    public function test_staff_can_create_artist_with_login()
    {
        $staff = $this->staffUser();

        $this->actingAs($staff)->post('/artists', [
            'nome' => 'Novo Artista',
            'email' => 'novo@vaif.com.br',
            'password' => 'password',
            'deposit_value' => 25,
        ])->assertRedirect();

        $this->assertDatabaseHas('artists', ['nome' => 'Novo Artista']);
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
