<?php

namespace App\Services\Agent;

/**
 * The database side of a grounded turn.
 *
 * Every method maps to a SECURITY DEFINER RPC; the agent never reads or writes
 * domain tables directly.
 */
interface AgentStore
{
    public function startTurn(string $artistId, string $chatId, string $phone, ?string $name, string $source = 'whatsapp'): array;

    public function facts(string $artistId, string $projectId, string $phone, ?string $intent, array $entities = []): array;

    public function bookSlot(string $artistId, string $projectId, string $startAt, int $durationMin = 120): ?array;

    public function reschedule(string $artistId, string $bookingId, string $startAt): ?array;

    public function apply(array $payload): array;

    public function log(array $payload): void;

    public function remember(string $leadId, string $leadText, string $reply): void;

    /**
     * @return array<int, array{role: string, content: string}>
     */
    public function history(string $leadId, int $limit = 8): array;

    public function chatOwner(string $artistId, string $chatId): string;

    public function takeControl(string $artistId, string $chatId, ?string $leadId = null): void;

    /**
     * Persist a message the artist typed on their own phone, so the agent's
     * memory matches what the lead actually received.
     */
    public function recordArtistMessage(string $artistId, string $chatId, string $text): void;

    /**
     * Appends to the chat's pending burst and returns the resulting row; its
     * `last_msg_at` is the token a queued job uses to detect staleness.
     */
    public function bufferAccumulate(string $artistId, string $chatId, string $text): array;

    public function bufferTyping(string $artistId, string $chatId, string $state): void;

    public function bufferState(string $artistId, string $chatId): ?array;

    public function bufferClear(string $artistId, string $chatId): void;

    /**
     * Merge scratch flags into the chat's `agent_state` (process explained,
     * price released, doubts cleared, photo confirmed).
     */
    public function patchChatState(string $artistId, string $chatId, array $patch): void;

    public function logDrop(?string $artistId, string $session, ?string $phone, ?string $chatId, string $reason, array $payload = []): void;
}
