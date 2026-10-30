<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\CalendarEntry;
use App\Models\Lead;
use App\Models\Project;
use App\Services\DepositService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class DepositServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sends_a_confirmation_to_the_lead_after_the_sinal(): void
    {
        Http::fake();
        config(['transport.waha_url' => 'https://waha.test']);

        $artist = Artist::create([
            'name' => 'Gabriel',
            'status' => 'live',
            'timezone' => 'America/Sao_Paulo',
            'wa_session_slug' => 'teste',
        ]);

        $lead = Lead::create([
            'id' => (string) Str::uuid(),
            'artist_id' => $artist->id,
            'name' => 'Cliente',
            'phone_e164' => '+5511900000000',
        ]);

        $project = Project::create([
            'id' => (string) Str::uuid(),
            'artist_id' => $artist->id,
            'lead_id' => $lead->id,
            'stage_key' => 'aguardando_deposito',
        ]);

        CalendarEntry::create([
            'id' => (string) Str::uuid(),
            'artist_id' => $artist->id,
            'kind' => 'booking',
            'project_id' => $project->id,
            'lead_id' => $lead->id,
            'start_at' => now()->addDays(2)->setTime(12, 0),
            'end_at' => now()->addDays(2)->setTime(14, 0),
            'active' => true,
            'created_by' => 'agent',
        ]);

        $sent = app(DepositService::class)->notifyConfirmed($project->fresh(['artist', 'lead']));

        $this->assertTrue($sent);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://waha.test/api/sendText'
                && $request['session'] === 'teste'
                && $request['chatId'] === '5511900000000@c.us'
                && str_contains((string) $request['text'], 'Sinal recebido!')
                && str_contains((string) $request['text'], 'confirmado para');
        });
    }
}
