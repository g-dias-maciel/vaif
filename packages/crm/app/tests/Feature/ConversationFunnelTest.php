<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\CalendarEntry;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Quote;
use App\Models\User;
use App\Services\Metrics\ConversationFunnel;
use App\Services\Metrics\TranscriptMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConversationFunnelTest extends TestCase
{
    use RefreshDatabase;

    private function artist(string $slug = 'funnel-artist'): Artist
    {
        return Artist::create([
            'name' => 'Funnel Artist',
            'status' => 'live',
            'timezone' => 'America/Sao_Paulo',
            'wa_session_slug' => $slug,
        ]);
    }

    private function lead(Artist $artist, ?string $seenAt = null, ?string $phone = null): Lead
    {
        return Lead::create([
            'id' => (string) Str::uuid(),
            'artist_id' => $artist->id,
            'name' => 'Lead',
            'phone_e164' => $phone ?? '+55119'.random_int(10000000, 99999999),
            'source' => 'whatsapp',
            'first_seen_at' => $seenAt !== null ? \Illuminate\Support\Carbon::parse($seenAt) : now()->subDays(5),
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

    private function quote(Artist $artist, Project $project, int $cents = 100000, ?string $createdAt = null): Quote
    {
        return Quote::create([
            'id' => (string) Str::uuid(),
            'artist_id' => $artist->id,
            'project_id' => $project->id,
            'kind' => 'standard',
            'base_price_cents' => $cents,
            'offered_price_cents' => $cents,
            'status' => 'open',
            'created_by' => 'artist_observed',
            'created_at' => $createdAt !== null ? \Illuminate\Support\Carbon::parse($createdAt) : now()->subDays(4),
        ]);
    }

    private function booking(Artist $artist, Project $project, Lead $lead, ?string $createdAt = null): CalendarEntry
    {
        $entry = CalendarEntry::create([
            'id' => (string) Str::uuid(),
            'artist_id' => $artist->id,
            'kind' => 'booking',
            'project_id' => $project->id,
            'lead_id' => $lead->id,
            'start_at' => now()->addDays(5),
            'end_at' => now()->addDays(5)->addHours(2),
            'active' => true,
            'created_by' => 'artist_observed',
        ]);

        if ($createdAt !== null) {
            $entry->forceFill(['created_at' => \Illuminate\Support\Carbon::parse($createdAt)])->save();
        }

        return $entry;
    }

    private function stageEvent(Artist $artist, Project $project, string $stage, string $at): void
    {
        DB::table('events')->insert([
            'artist_id' => $artist->id,
            'lead_id' => $project->lead_id,
            'project_id' => $project->id,
            'actor' => 'agent',
            'type' => 'stage_changed',
            'payload' => json_encode(['stage' => $stage]),
            'created_at' => \Illuminate\Support\Carbon::parse($at),
        ]);
    }

    private function funnel(array $transcripts = [], bool $available = true): ConversationFunnel
    {
        return new ConversationFunnel(new FakeTranscriptMetrics($transcripts, $available));
    }

    public function test_it_computes_the_conversation_funnel(): void
    {
        $artist = $this->artist();

        $l1 = $this->lead($artist, now()->subDays(5)->toIso8601String(), '+5511900000001');
        $l2 = $this->lead($artist, now()->subDays(4)->toIso8601String(), '+5511900000002');
        $l3 = $this->lead($artist, now()->subDays(3)->toIso8601String(), '+5511900000003');
        $l4 = $this->lead($artist, now()->subDays(2)->toIso8601String(), '+5511900000004');

        $p1 = $this->project($artist, $l1, 'qualificando');
        $p2 = $this->project($artist, $l2, 'orcamento_enviado');
        $p3 = $this->project($artist, $l3, 'agendado');
        $p4 = $this->project($artist, $l4, 'novo');

        $this->quote($artist, $p1);
        $this->quote($artist, $p2);
        $this->quote($artist, $p4);

        $this->booking($artist, $p2, $l2);
        $this->booking($artist, $p3, $l3);

        $this->stageEvent($artist, $p1, 'fechado', now()->subDay()->toIso8601String());
        $this->stageEvent($artist, $p4, 'perdido', now()->subDay()->toIso8601String());

        $transcripts = [
            $l1->id => [
                'first_inbound' => now()->subDays(5)->setTime(10, 0)->toIso8601String(),
                'first_outbound' => now()->subDays(5)->setTime(10, 10)->toIso8601String(),
                'last_inbound' => now()->subDays(5)->setTime(10, 0)->toIso8601String(),
                'last_outbound' => now()->subDays(5)->setTime(10, 10)->toIso8601String(),
            ],
            $l2->id => [
                'first_inbound' => now()->subDays(4)->setTime(10, 0)->toIso8601String(),
                'first_outbound' => now()->subDays(4)->setTime(10, 30)->toIso8601String(),
                'last_inbound' => now()->subDays(4)->setTime(11, 0)->toIso8601String(),
                'last_outbound' => now()->subDays(4)->setTime(10, 30)->toIso8601String(),
            ],
            $l3->id => [
                'first_inbound' => now()->subDays(3)->setTime(10, 0)->toIso8601String(),
                'first_outbound' => now()->subDays(3)->setTime(10, 20)->toIso8601String(),
                'last_inbound' => now()->subDays(3)->setTime(11, 0)->toIso8601String(),
                'last_outbound' => now()->subDays(3)->setTime(10, 20)->toIso8601String(),
            ],
            $l4->id => [
                'first_inbound' => now()->subDays(2)->setTime(10, 0)->toIso8601String(),
                'first_outbound' => now()->subDays(2)->setTime(9, 30)->toIso8601String(),
                'last_inbound' => now()->subDays(2)->setTime(10, 0)->toIso8601String(),
                'last_outbound' => now()->subDays(2)->setTime(9, 30)->toIso8601String(),
            ],
        ];

        $funnel = $this->funnel($transcripts)->forArtist($artist, now()->subDays(30), now());

        $this->assertSame(4, $funnel['leads']);
        $this->assertSame(3, $funnel['responded']);
        $this->assertSame(20, $funnel['first_response_minutes']);
        $this->assertSame(3, $funnel['qualified']);
        $this->assertSame(3, $funnel['quoted']);
        $this->assertSame(2, $funnel['booked']);
        $this->assertSame(2, $funnel['bookings_created']);
        $this->assertSame(1, $funnel['closed']);
        $this->assertSame(1, $funnel['lost']);
        $this->assertSame(0, $funnel['ghosted']);
        $this->assertSame(0.5, $funnel['rates']['closing']);
        $this->assertSame(0.6667, $funnel['rates']['quote_to_booking']);
        $this->assertSame(0.5, $funnel['rates']['booking_to_closed']);
        $this->assertSame(0.75, $funnel['rates']['response']);
    }

    public function test_it_counts_ghosted_only_when_the_studio_spoke_last(): void
    {
        $artist = $this->artist();
        $l1 = $this->lead($artist, now()->subDays(20)->toIso8601String(), '+5511900000001');
        $l2 = $this->lead($artist, now()->subDays(20)->toIso8601String(), '+5511900000002');
        $this->project($artist, $l1, 'novo');
        $this->project($artist, $l2, 'novo');

        $transcripts = [
            $l1->id => [
                'first_inbound' => now()->subDays(10)->toIso8601String(),
                'first_outbound' => now()->subDays(9)->toIso8601String(),
                'last_inbound' => now()->subDays(10)->toIso8601String(),
                'last_outbound' => now()->subDays(8)->toIso8601String(),
            ],
            $l2->id => [
                'first_inbound' => now()->subDays(10)->toIso8601String(),
                'first_outbound' => now()->subDays(9)->toIso8601String(),
                'last_inbound' => now()->subDays(8)->toIso8601String(),
                'last_outbound' => now()->subDays(9)->toIso8601String(),
            ],
        ];

        $funnel = $this->funnel($transcripts)->forArtist($artist, now()->subDays(30), now());

        $this->assertSame(1, $funnel['ghosted']);
        $this->assertSame(0.5, $funnel['rates']['ghosted']);
    }

    public function test_it_filters_by_the_lead_cohort_period(): void
    {
        $artist = $this->artist();
        $inside = $this->lead($artist, now()->subDays(5)->toIso8601String(), '+5511900000001');
        $outside = $this->lead($artist, now()->subDays(60)->toIso8601String(), '+5511900000002');
        $this->project($artist, $inside, 'novo');
        $this->project($artist, $outside, 'fechado');

        $funnel = $this->funnel([], false)->forArtist($artist, now()->subDays(30), now());

        $this->assertSame(1, $funnel['leads']);
        $this->assertSame(0, $funnel['closed']);
    }

    public function test_the_analytics_and_staff_pages_render_the_funnel(): void
    {
        $artist = $this->artist();
        $lead = $this->lead($artist);
        $this->project($artist, $lead, 'qualificando');

        $artistUser = User::create([
            'name' => 'Artist',
            'email' => 'funnel-artist-'.Str::random(6).'@vaif.test',
            'password' => Hash::make('password'),
            'role' => User::ROLE_ARTIST,
            'artist_id' => $artist->id,
        ]);

        $this->actingAs($artistUser)
            ->get(route('analytics.index'))
            ->assertOk()
            ->assertSee('Funil de conversa')
            ->assertSee('Novos leads');

        $staff = User::create([
            'name' => 'Staff',
            'email' => 'funnel-staff-'.Str::random(6).'@vaif.test',
            'password' => Hash::make('password'),
            'role' => User::ROLE_STAFF,
        ]);

        $this->actingAs($staff)
            ->get(route('metrics.index'))
            ->assertOk()
            ->assertSee('funil de conversa')
            ->assertSee('Funnel Artist');
    }
}

final class FakeTranscriptMetrics implements TranscriptMetrics
{
    /**
     * @param  array<string, array<string, ?string>>  $data
     */
    public function __construct(
        private readonly array $data = [],
        private readonly bool $available = true,
    ) {}

    public function available(): bool
    {
        return $this->available;
    }

    public function forLeads(array $leadIds): array
    {
        return array_intersect_key($this->data, array_flip($leadIds));
    }
}
