<?php

namespace Tests\Feature;

use App\Models\AgentDraft;
use App\Models\Artist;
use App\Models\Lead;
use App\Services\Agent\CalibrationConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CalibrationConversationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_a_client_draft_turn(): void
    {
        $artist = Artist::create([
            'name' => 'Conversation Artist',
            'status' => 'live',
            'timezone' => 'America/Sao_Paulo',
            'wa_session_slug' => 'conversation-artist',
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
            'chat_id' => 'conversation-artist:5511900000000@c.us',
            'turn_id' => (string) Str::uuid(),
            'lead_text' => 'Quanto fica uma tattoo no braço?',
            'draft_reply' => 'Fica R$ 600, qual o tamanho?',
            'status' => AgentDraft::STATUS_PENDING,
        ]);

        $turns = (new CalibrationConversation())->forLead($lead);

        $this->assertCount(1, $turns);
        $this->assertSame('Quanto fica uma tattoo no braço?', $turns[0]['clients'][0]['content']);
        $this->assertNotNull($turns[0]['draft']);
        $this->assertSame('Fica R$ 600, qual o tamanho?', $turns[0]['draft']['draft']->draft_reply);

        $html = view('calibration._conversation', [
            'turns' => $turns,
            'artistName' => 'Adriano',
        ])->render();

        $this->assertStringContainsString('Beatriz teria respondido', $html);
        $this->assertStringContainsString('Fica R$ 600, qual o tamanho?', $html);
    }
}
