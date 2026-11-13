<?php

namespace Tests\Feature;

use App\Models\AgentDraft;
use App\Models\Artist;
use App\Models\Lead;
use App\Services\Agent\ConversationTimeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConversationTimelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_falls_back_to_the_paired_artist_reply_when_memory_is_missing(): void
    {
        $artist = Artist::create([
            'name' => 'Timeline Artist',
            'status' => 'live',
            'timezone' => 'America/Sao_Paulo',
            'wa_session_slug' => 'timeline-artist',
        ]);

        $lead = Lead::create([
            'id' => (string) Str::uuid(),
            'artist_id' => $artist->id,
            'name' => 'Lead',
            'phone_e164' => '+5511900000000',
        ]);

        $draft = AgentDraft::create([
            'artist_id' => $artist->id,
            'lead_id' => $lead->id,
            'chat_id' => 'timeline-artist:5511900000000@c.us',
            'turn_id' => (string) Str::uuid(),
            'lead_text' => 'Quanto fica?',
            'draft_reply' => 'Fica R$ 600.',
            'status' => AgentDraft::STATUS_REPLIED,
            'artist_reply' => 'Fica R$ 600, qual o tamanho?',
            'artist_replied_at' => now()->subMinutes(1),
        ]);
        $draft->forceFill(['created_at' => now()->subMinutes(5)])->save();

        $timeline = (new ConversationTimeline())->forLead($lead);

        $this->assertCount(2, $timeline);
        $this->assertSame('draft', $timeline[0]['kind']);
        $this->assertSame('artist', $timeline[1]['kind']);
        $this->assertSame('Fica R$ 600, qual o tamanho?', $timeline[1]['content']);
    }

    public function test_it_shows_the_staff_description_of_artist_media(): void
    {
        $artist = Artist::create([
            'name' => 'Timeline Artist',
            'status' => 'live',
            'timezone' => 'America/Sao_Paulo',
            'wa_session_slug' => 'timeline-artist',
        ]);

        $lead = Lead::create([
            'id' => (string) Str::uuid(),
            'artist_id' => $artist->id,
            'name' => 'Lead',
            'phone_e164' => '+5511900000000',
        ]);

        $draft = AgentDraft::create([
            'artist_id' => $artist->id,
            'lead_id' => $lead->id,
            'chat_id' => 'timeline-artist:5511900000000@c.us',
            'turn_id' => (string) Str::uuid(),
            'lead_text' => 'Quanto fica?',
            'draft_reply' => 'Fica R$ 600.',
            'status' => AgentDraft::STATUS_COMPARED,
            'artist_reply' => '[MÍDIA ENVIADA]',
            'artist_media_note' => 'Mandou a tabela de preços, R$ 600 no braço.',
            'artist_replied_at' => now()->subMinute(),
        ]);
        $draft->forceFill(['created_at' => now()->subMinutes(5)])->save();

        $timeline = (new ConversationTimeline())->forLead($lead);
        $artistItem = collect($timeline)->firstWhere('kind', 'artist');

        $this->assertNotNull($artistItem);
        $this->assertStringContainsString('tabela de preços', $artistItem['content']);
    }

    public function test_it_does_not_repeat_the_same_media_note(): void
    {
        $artist = Artist::create([
            'name' => 'Timeline Artist',
            'status' => 'live',
            'timezone' => 'America/Sao_Paulo',
            'wa_session_slug' => 'timeline-artist',
        ]);

        $lead = Lead::create([
            'id' => (string) Str::uuid(),
            'artist_id' => $artist->id,
            'name' => 'Lead',
            'phone_e164' => '+5511900000000',
        ]);

        foreach ([5, 2] as $minutes) {
            $draft = AgentDraft::create([
                'artist_id' => $artist->id,
                'lead_id' => $lead->id,
                'chat_id' => 'timeline-artist:5511900000000@c.us',
                'turn_id' => (string) Str::uuid(),
                'lead_text' => 'Quanto fica?',
                'draft_reply' => 'Fica R$ 600.',
                'status' => AgentDraft::STATUS_COMPARED,
                'artist_reply' => '[ÁUDIO ENVIADO]',
                'artist_media_note' => 'Áudio do artista: teste de transcrição.',
                'artist_replied_at' => now()->subMinutes($minutes),
            ]);
            $draft->forceFill(['created_at' => now()->subMinutes($minutes + 2)])->save();
        }

        $timeline = (new ConversationTimeline())->forLead($lead);
        $content = implode("\n", array_map(fn ($item) => (string) ($item['content'] ?? ''), $timeline));

        $this->assertSame(1, substr_count($content, 'Áudio do artista: teste de transcrição.'));
    }

    public function test_it_merges_visible_drafts_into_the_lead_timeline(): void
    {
        $artist = Artist::create([
            'name' => 'Timeline Artist',
            'status' => 'live',
            'timezone' => 'America/Sao_Paulo',
            'wa_session_slug' => 'timeline-artist',
        ]);

        $lead = Lead::create([
            'id' => (string) Str::uuid(),
            'artist_id' => $artist->id,
            'name' => 'Lead',
            'phone_e164' => '+5511900000000',
        ]);

        AgentDraft::create([
            'artist_id' => $artist->id,
            'lead_id' => $lead->id,
            'chat_id' => 'timeline-artist:5511900000000@c.us',
            'turn_id' => (string) Str::uuid(),
            'lead_text' => 'Quanto fica?',
            'draft_reply' => 'Fica R$ 600.',
            'status' => AgentDraft::STATUS_PENDING,
        ]);

        AgentDraft::create([
            'artist_id' => $artist->id,
            'lead_id' => $lead->id,
            'chat_id' => 'timeline-artist:5511900000000@c.us',
            'turn_id' => (string) Str::uuid(),
            'lead_text' => 'Quanto fica?',
            'draft_reply' => 'Rascunho antigo',
            'status' => AgentDraft::STATUS_SUPERSEDED,
        ]);

        $timeline = (new ConversationTimeline())->forLead($lead);

        $this->assertCount(1, $timeline);
        $this->assertSame('draft', $timeline[0]['kind']);
        $this->assertSame('Fica R$ 600.', $timeline[0]['content']);
        $this->assertSame('Beatriz · sugestão não enviada', $timeline[0]['sender']);
    }
}
