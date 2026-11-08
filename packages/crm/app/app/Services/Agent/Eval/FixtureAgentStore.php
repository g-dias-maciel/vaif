<?php

namespace App\Services\Agent\Eval;

use App\Models\Artist;
use App\Services\Agent\AgentStore;

/**
 * In-memory AgentStore for scenarios and tests.
 *
 * It returns the CRM fixtures a scenario declares instead of calling the
 * Postgres RPCs, but the agent, prompts, validator and repair loop are the
 * real production ones.
 */
class FixtureAgentStore implements AgentStore
{
    /** @var array<int, array<string, mixed>> */
    public array $applied = [];

    /** @var array<int, array<string, mixed>> */
    public array $logs = [];

    /** @var array<int, array{lead: string, text: string, reply: string}> */
    public array $remembered = [];

    /** @var array<int, string> */
    public array $drops = [];

    /** @var array<int, array{chat_id: string, text: string}> */
    public array $accumulated = [];

    /** @var array<int, array{chat_id: string, state: string}> */
    public array $typingStates = [];

    /** @var array<int, string> */
    public array $controls = [];

    /** @var array<int, array{chat_id: string, text: string}> */
    public array $artistMessages = [];

    public ?array $state = null;

    public string $owner = 'agent';

    public function __construct(private readonly array $scenario)
    {
    }

    public function startTurn(string $artistId, string $chatId, string $phone, ?string $name, string $source = 'whatsapp'): array
    {
        return array_merge([
            'lead_id' => '00000000-0000-4000-8000-000000000001',
            'project_id' => '00000000-0000-4000-8000-000000000002',
            'is_new' => true,
            'name' => $name,
            'phone_e164' => $phone,
            'stage_key' => 'novo',
            'agent_state' => [],
            'agent_blocked' => false,
            'active_booking_id' => null,
        ], $this->scenario['turn'] ?? []);
    }

    public function facts(string $artistId, string $projectId, string $phone, ?string $intent, array $entities = []): array
    {
        $facts = $this->scenario['facts'] ?? [];

        if (isset($this->scenario['facts_by_intent'][$intent])) {
            $facts = array_replace_recursive($facts, $this->scenario['facts_by_intent'][$intent]);
        }

        $facts['turn'] = array_merge($facts['turn'] ?? [], ['intent' => $intent]);

        return $facts;
    }

    public function bookSlot(string $artistId, string $projectId, string $startAt, int $durationMin = 120): ?array
    {
        return [
            'id' => '00000000-0000-4000-8000-0000000000b1',
            'start_at' => $startAt,
            'end_at' => $startAt,
            'created_this_turn' => true,
        ];
    }

    public function reschedule(string $artistId, string $bookingId, string $startAt): ?array
    {
        return [
            'id' => $bookingId,
            'start_at' => $startAt,
            'end_at' => $startAt,
            'created_this_turn' => true,
        ];
    }

    public function apply(array $payload): array
    {
        $this->applied[] = $payload;

        return [
            'duplicate' => false,
            'handoff' => (bool) ($this->scenario['handoff'] ?? false),
            'stage_key' => $this->scenario['result_stage'] ?? ($payload['intent'] ?? null),
        ];
    }

    public function log(array $payload): void
    {
        $this->logs[] = $payload;
    }

    public function remember(string $leadId, string $leadText, string $reply): void
    {
        $this->remembered[] = ['lead' => $leadId, 'text' => $leadText, 'reply' => $reply];
    }

    public function history(string $leadId, int $limit = 8): array
    {
        return array_slice($this->scenario['history'] ?? [], -$limit);
    }

    public function chatOwner(string $artistId, string $chatId): string
    {
        return $this->owner;
    }

    public function takeControl(string $artistId, string $chatId, ?string $leadId = null): void
    {
        $this->controls[] = $chatId;
    }

    public function recordArtistMessage(string $artistId, string $chatId, string $text): void
    {
        $this->artistMessages[] = ['chat_id' => $chatId, 'text' => $text];
    }

    public function bufferAccumulate(string $artistId, string $chatId, string $text): array
    {
        $this->accumulated[] = ['chat_id' => $chatId, 'text' => $text];

        return ['last_msg_at' => date('c')];
    }

    public function bufferTyping(string $artistId, string $chatId, string $state): void
    {
        $this->typingStates[] = ['chat_id' => $chatId, 'state' => $state];
    }

    public function bufferState(string $artistId, string $chatId): ?array
    {
        return $this->state;
    }

    public function bufferClear(string $artistId, string $chatId): void
    {
        $this->state = null;
        $this->cleared[] = $chatId;
    }

    /** @var array<int, string> */
    public array $cleared = [];

    public function patchChatState(string $artistId, string $chatId, array $patch): void
    {
        $this->chatPatches[] = $patch;
    }

    public function logDrop(?string $artistId, string $session, ?string $phone, ?string $chatId, string $reason, array $payload = []): void
    {
        $this->drops[] = $reason;
    }

    /** @var array<int, array<string, mixed>> */
    public array $chatPatches = [];
}
