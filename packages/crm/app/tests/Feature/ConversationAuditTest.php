<?php

namespace Tests\Feature;

use App\Jobs\AuditConversation;
use App\Models\Artist;
use App\Models\ConversationAudit;
use App\Models\Lead;
use App\Models\User;
use App\Services\Agent\CalibrationReport;
use App\Services\Agent\ConversationAuditor;
use App\Services\Agent\Eval\FixtureAgentStore;
use App\Services\Agent\Eval\ScriptedAiClient;
use App\Services\Copilot\CalibrationFacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConversationAuditTest extends TestCase
{
    use RefreshDatabase;

    private function artist(string $slug = 'audit-artist'): Artist
    {
        return Artist::create([
            'name' => 'Audit Artist',
            'status' => 'live',
            'timezone' => 'America/Sao_Paulo',
            'wa_session_slug' => $slug,
        ]);
    }

    private function lead(Artist $artist): Lead
    {
        return Lead::create([
            'id' => (string) Str::uuid(),
            'artist_id' => $artist->id,
            'name' => 'Ana',
            'phone_e164' => '+5511999999999',
            'source' => 'whatsapp',
            'first_seen_at' => now()->subDay(),
        ]);
    }

    private function store(): FixtureAgentStore
    {
        return new FixtureAgentStore([
            'history' => [
                ['role' => 'user', 'content' => 'Oi, quero uma tattoo no braço'],
                ['role' => 'assistant', 'content' => 'Oi! Sou o estúdio. Qual o tamanho e o estilo?'],
                ['role' => 'user', 'content' => 'Fechamento, blackwork'],
                ['role' => 'assistant', 'content' => 'Fica R$ 1.800, com sinal de R$ 500.'],
                ['role' => 'user', 'content' => 'Pode ser sábado?'],
                ['role' => 'assistant', 'content' => 'Consigo sábado 14h, fica pré-reservado.'],
            ],
        ]);
    }

    private function script(): array
    {
        return [
            'audit' => [
                'summary' => 'Atendimento direto, mas orçou antes de confirmar o tamanho.',
                'steps' => [
                    ['key' => 'introduction', 'status' => 'pass', 'evidence' => 'Estúdio: Oi! Sou o estúdio.'],
                    ['key' => 'qualification_before_price', 'status' => 'fail', 'evidence' => 'Estúdio: Fica R$ 1.800', 'note' => 'orçou antes do tamanho'],
                    ['key' => 'price_explained', 'status' => 'pass', 'evidence' => 'Estúdio: com sinal de R$ 500'],
                ],
            ],
        ];
    }

    public function test_the_auditor_normalizes_the_rubric_and_scores_it(): void
    {
        $artist = $this->artist();
        $lead = $this->lead($artist);
        $store = $this->store();
        $ai = new ScriptedAiClient($this->script());

        $auditor = new ConversationAuditor($ai, $store);
        $prepared = $auditor->prepare($artist, $lead);

        $this->assertNotNull($prepared);
        $this->assertSame(6, $prepared['message_count']);
        $this->assertNotEmpty($prepared['hash']);

        $result = $auditor->review($artist, $lead, $prepared);

        $this->assertSame(66.7, $result['score']);
        $this->assertCount(10, $result['steps']);
        $this->assertSame('pass', $result['steps'][0]['status']);
        $this->assertSame('fail', $result['steps'][1]['status']);
        $this->assertSame('pass', $result['steps'][2]['status']);
        $this->assertSame('na', $result['steps'][3]['status']);
        $this->assertNotEmpty($result['steps'][1]['evidence']);
    }

    public function test_the_job_stores_one_audit_per_transcript(): void
    {
        $artist = $this->artist();
        $lead = $this->lead($artist);
        $store = $this->store();
        $ai = new ScriptedAiClient($this->script());
        $auditor = new ConversationAuditor($ai, $store);

        (new AuditConversation($artist->id, (string) $lead->id))->handle($auditor);

        $this->assertSame(1, ConversationAudit::count());
        $audit = ConversationAudit::firstOrFail();
        $this->assertSame(ConversationAudit::STATUS_READY, $audit->status);
        $this->assertSame(66.7, $audit->score);
        $this->assertCount(10, $audit->orderedSteps());
        $this->assertCount(1, $audit->failedSteps());

        $calls = count($ai->calls);

        (new AuditConversation($artist->id, (string) $lead->id))->handle($auditor);

        $this->assertSame(1, ConversationAudit::count());
        $this->assertCount($calls, $ai->calls);
    }

    public function test_the_lead_page_shows_the_audit_only_to_staff(): void
    {
        $artist = $this->artist();
        $lead = $this->lead($artist);

        ConversationAudit::create([
            'artist_id' => $artist->id,
            'lead_id' => $lead->id,
            'chat_id' => (string) $lead->id,
            'transcript_hash' => hash('sha256', 'fixture'),
            'status' => ConversationAudit::STATUS_READY,
            'score' => 80,
            'summary' => 'Bom atendimento, faltou pedir o sinal.',
            'steps' => [
                ['key' => 'introduction', 'status' => 'pass', 'evidence' => 'Oi!'],
                ['key' => 'qualification_before_price', 'status' => 'fail', 'evidence' => 'Fica R$ 1.800'],
            ],
            'message_count' => 8,
            'generated_at' => now(),
        ]);

        $artistUser = User::create([
            'name' => 'Artist',
            'email' => 'audit-artist-'.Str::random(6).'@vaif.test',
            'password' => Hash::make('password'),
            'role' => User::ROLE_ARTIST,
            'artist_id' => $artist->id,
        ]);

        $this->actingAs($artistUser)
            ->get(route('leads.show', $lead))
            ->assertOk()
            ->assertDontSee('Auditoria da metodologia');

        $staff = User::create([
            'name' => 'Staff',
            'email' => 'audit-staff-'.Str::random(6).'@vaif.test',
            'password' => Hash::make('password'),
            'role' => User::ROLE_STAFF,
        ]);

        $this->actingAs($staff)
            ->get(route('leads.show', $lead))
            ->assertOk()
            ->assertSee('Auditoria da metodologia')
            ->assertSee('Qualificou antes de orçar');
    }

    public function test_staff_can_queue_an_audit_on_demand(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);

        $artist = $this->artist();
        $lead = $this->lead($artist);
        $staff = User::create([
            'name' => 'Staff',
            'email' => 'audit-queue-'.Str::random(6).'@vaif.test',
            'password' => Hash::make('password'),
            'role' => User::ROLE_STAFF,
        ]);

        Queue::fake();

        $this->actingAs($staff)
            ->post(route('leads.audit', $lead))
            ->assertRedirect();

        Queue::assertPushed(AuditConversation::class, fn (AuditConversation $job) => $job->artistId === $artist->id
            && $job->leadId === (string) $lead->id
            && $job->force === true);
    }

    public function test_copilot_facts_include_the_audit_summary(): void
    {
        $artist = $this->artist();
        $lead = $this->lead($artist);

        ConversationAudit::create([
            'artist_id' => $artist->id,
            'lead_id' => $lead->id,
            'chat_id' => (string) $lead->id,
            'transcript_hash' => hash('sha256', 'copilot'),
            'status' => ConversationAudit::STATUS_READY,
            'score' => 70,
            'summary' => 'Faltou o sinal.',
            'steps' => [
                ['key' => 'deposit_requested', 'status' => 'fail', 'evidence' => 'fechado, reservado'],
                ['key' => 'introduction', 'status' => 'pass', 'evidence' => 'Oi!'],
            ],
            'message_count' => 8,
            'generated_at' => now(),
        ]);

        $facts = (new CalibrationFacts(new CalibrationReport))->collect();
        $row = collect($facts['artists'])->firstWhere('artist', 'Audit Artist');

        $this->assertNotNull($row);
        $this->assertSame(1, $row['audit']['count']);
        $this->assertSame(70.0, $row['audit']['avg_score']);
        $this->assertSame('deposit_requested', $row['audit']['top_missed'][0]['step']);
    }
}
