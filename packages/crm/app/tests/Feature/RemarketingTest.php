<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\CalendarEntry;
use App\Models\Event;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class RemarketingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
    }

    private function artist(string $slug = 'remarketing'): Artist
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
            'email' => 'remarketing-'.$artist->id.'@vaif.test',
            'password' => Hash::make('password'),
            'role' => User::ROLE_ARTIST,
            'artist_id' => $artist->id,
        ]);
    }

    private function lead(
        Artist $artist,
        string $name,
        string $phone,
        int $silentDays = 10,
    ): Lead {
        return Lead::create([
            'id' => (string) Str::uuid(),
            'artist_id' => $artist->id,
            'name' => $name,
            'phone_e164' => $phone,
            'source' => 'whatsapp',
            'first_seen_at' => now()->subDays($silentDays + 30),
            'last_message_at' => now()->subDays($silentDays),
        ]);
    }

    private function project(Artist $artist, Lead $lead, string $stage): Project
    {
        return Project::create([
            'id' => (string) Str::uuid(),
            'artist_id' => $artist->id,
            'lead_id' => $lead->id,
            'stage_key' => $stage,
        ]);
    }

    private function fullBrief(Project $project): Project
    {
        $project->forceFill([
            'placement' => 'braco',
            'body_zone' => 'fechamento',
            'style' => 'blackwork',
            'tattoo_type' => 'new',
            'first_tattoo' => true,
            'meaning' => 'Homenagem',
            'reference_pics' => ['https://example.com/ref.jpg'],
        ])->save();

        return $project;
    }

    private function quote(Project $project, string $status = 'accepted', int $cents = 180000): Quote
    {
        return Quote::create([
            'id' => (string) Str::uuid(),
            'artist_id' => $project->artist_id,
            'project_id' => $project->id,
            'kind' => 'standard',
            'base_price_cents' => $cents,
            'offered_price_cents' => $cents,
            'status' => $status,
            'created_by' => 'artist',
            'created_at' => now()->subDays(2),
            'accepted_at' => $status === 'accepted' ? now()->subDay() : null,
        ]);
    }

    private function deposit(Project $project, string $status = 'requested'): void
    {
        $project->payments()->create([
            'id' => (string) Str::uuid(),
            'artist_id' => $project->artist_id,
            'project_id' => $project->id,
            'kind' => 'deposit',
            'amount_cents' => 30000,
            'status' => $status,
            'requested_at' => now()->subDays(2),
        ]);
    }

    private function booking(Artist $artist, Project $project, Lead $lead): CalendarEntry
    {
        return CalendarEntry::create([
            'id' => (string) Str::uuid(),
            'artist_id' => $artist->id,
            'kind' => 'booking',
            'project_id' => $project->id,
            'lead_id' => $lead->id,
            'start_at' => now()->addDays(3),
            'end_at' => now()->addDays(3)->addHours(2),
            'active' => true,
            'created_by' => 'artist',
        ]);
    }

    private function touch(Lead $lead, string $action, int $daysAgo = 1): Event
    {
        return Event::create([
            'artist_id' => $lead->artist_id,
            'lead_id' => $lead->id,
            'actor' => 'artist',
            'type' => 'remarketing_touch',
            'payload' => ['action' => $action, 'segment' => 'retomar'],
            'created_at' => now()->subDays($daysAgo),
        ]);
    }

    public function test_the_page_is_scoped_to_the_active_artist(): void
    {
        $artist = $this->artist();
        $other = $this->artist('other');
        $user = $this->user($artist);

        $own = $this->lead($artist, 'Cliente Próprio', '+5511900000001');
        $this->project($artist, $own, 'qualificando');

        $foreign = $this->lead($other, 'Cliente Alheio', '+5511900000002');
        $this->project($other, $foreign, 'qualificando');

        $this->actingAs($user)->get('/remarketing')
            ->assertOk()
            ->assertSee('Remarketing — Artist remarketing')
            ->assertSee('Cliente Próprio')
            ->assertDontSee('Cliente Alheio');
    }

    public function test_it_ranks_stalled_open_leads_by_chance_to_close(): void
    {
        $artist = $this->artist();
        $user = $this->user($artist);

        $hot = $this->lead($artist, 'Marina Alves', '+5521999553136', 10);
        $hotProject = $this->fullBrief($this->project($artist, $hot, 'orcamento_enviado'));
        $this->quote($hotProject, 'accepted');

        $cold = $this->lead($artist, 'João Pedro', '+5521988887777', 10);
        $this->project($artist, $cold, 'novo');

        $this->actingAs($user)->get('/remarketing')
            ->assertOk()
            ->assertSeeInOrder(['Marina Alves', 'João Pedro'])
            ->assertSee('Quente')
            ->assertSee('Frio');
    }

    public function test_it_filters_by_temperature_and_by_silence_window(): void
    {
        $artist = $this->artist();
        $user = $this->user($artist);

        $hot = $this->lead($artist, 'Marina Alves', '+5521999553136', 10);
        $hotProject = $this->fullBrief($this->project($artist, $hot, 'orcamento_enviado'));
        $this->quote($hotProject, 'accepted');

        $recent = $this->lead($artist, 'Contato Recente', '+5521977776666', 2);
        $this->project($artist, $recent, 'qualificando');

        $this->actingAs($user)->get('/remarketing?tier=hot')
            ->assertOk()
            ->assertSee('Marina Alves')
            ->assertDontSee('Contato Recente');

        $this->actingAs($user)->get('/remarketing?days=15')
            ->assertOk()
            ->assertDontSee('Contato Recente');

        $this->actingAs($user)->get('/remarketing?segment=abertos')
            ->assertOk()
            ->assertSee('Contato Recente');
    }

    public function test_a_lead_contacted_recently_leaves_the_retomar_queue_but_stays_in_abertos(): void
    {
        $artist = $this->artist();
        $user = $this->user($artist);

        $lead = $this->lead($artist, 'Marina Alves', '+5521999553136', 10);
        $this->project($artist, $lead, 'qualificando');
        $this->touch($lead, 'marked_contacted');

        $this->actingAs($user)->get('/remarketing')
            ->assertOk()
            ->assertDontSee('Marina Alves');

        $this->actingAs($user)->get('/remarketing?segment=abertos')
            ->assertOk()
            ->assertSee('Marina Alves');
    }

    public function test_revival_lists_lost_projects_that_had_engagement(): void
    {
        $artist = $this->artist();
        $user = $this->user($artist);

        $revivable = $this->lead($artist, 'Carla Souza', '+5511966665555', 60);
        $lost = $this->fullBrief($this->project($artist, $revivable, 'perdido'));
        $lost->forceFill(['closed_at' => now()->subDays(45)])->save();
        $this->quote($lost, 'lapsed');

        $noise = $this->lead($artist, 'Sem Conversa', '+5511955554444', 60);
        $noiseProject = $this->project($artist, $noise, 'perdido');
        $noiseProject->forceFill(['closed_at' => now()->subDays(45)])->save();

        $this->actingAs($user)->get('/remarketing?segment=reviver')
            ->assertOk()
            ->assertSee('Carla Souza')
            ->assertDontSee('Sem Conversa');
    }

    public function test_whatsapp_action_logs_the_touch_and_redirects_to_wa_me(): void
    {
        $artist = $this->artist();
        $user = $this->user($artist);

        $lead = $this->lead($artist, 'Marina Alves', '+5521999553136', 10);
        $project = $this->project($artist, $lead, 'orcamento_enviado');

        $response = $this->actingAs($user)->post(route('remarketing.contact', $lead), [
            'action' => 'whatsapp',
            'segment' => 'retomar',
            'project_id' => $project->id,
        ]);

        $response->assertRedirect();
        $this->assertStringStartsWith('https://wa.me/5521999553136', (string) $response->headers->get('Location'));

        $event = Event::query()->where('type', 'remarketing_touch')->firstOrFail();
        $this->assertSame('whatsapp_opened', $event->payload['action'] ?? null);
        $this->assertSame($lead->id, (string) $event->lead_id);
    }

    public function test_an_artist_cannot_act_on_another_artists_lead(): void
    {
        $owner = $this->artist('owner');
        $other = $this->artist('other');
        $lead = $this->lead($owner, 'Cliente do Owner', '+5511900000003');
        $this->project($owner, $lead, 'qualificando');

        $this->actingAs($this->user($other))
            ->post(route('remarketing.contact', $lead), [
                'action' => 'contacted',
                'segment' => 'retomar',
            ])
            ->assertForbidden();
    }

    public function test_it_uses_the_booking_and_deposit_as_positive_signals(): void
    {
        $artist = $this->artist();
        $user = $this->user($artist);

        $depositLead = $this->lead($artist, 'Sinal Pendente', '+5511944443333', 10);
        $depositProject = $this->project($artist, $depositLead, 'aguardando_deposito');
        $this->deposit($depositProject);

        $plainLead = $this->lead($artist, 'Só Conversa', '+5511933332222', 10);
        $this->project($artist, $plainLead, 'qualificando');

        $this->actingAs($user)->get('/remarketing')
            ->assertOk()
            ->assertSeeInOrder(['Sinal Pendente', 'Só Conversa'])
            ->assertSee('Sinal solicitado');
    }
}
