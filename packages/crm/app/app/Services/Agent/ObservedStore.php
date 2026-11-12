<?php

namespace App\Services\Agent;

use App\Models\ObservedWrite;

/**
 * Persistence for observe mode.
 *
 * Deliberately separate from `AgentStore`: the observer never reads facts for
 * a reply, never applies a grounded turn and never touches the transport.
 */
interface ObservedStore
{
    /**
     * Record one interpreted turn. Returns false when the turn was already
     * observed (same WAHA message id or same timestamp).
     *
     * @param  array<string, mixed>  $turn
     */
    public function saveTurn(array $turn): bool;

    /**
     * Create (or return the existing) review-queue item for a dedupe key.
     *
     * @param  array<string, mixed>  $write
     */
    public function createWrite(array $write): ?ObservedWrite;

    /**
     * Write explicit discovery fields and the Lead name.
     *
     * @param  array<string, mixed>  $entities
     * @return array<string, mixed>
     */
    public function applyFields(
        string $artistId,
        string $projectId,
        string $phone,
        array $entities,
        string $origin = 'artist',
    ): array;

    /**
     * @return array<string, mixed>|null
     */
    public function writeQuote(
        string $artistId,
        string $projectId,
        int $baseCents,
        int $offeredCents,
        string $actor = 'artist',
    ): ?array;

    /**
     * @return array<string, mixed>|null
     */
    public function requestDeposit(
        string $artistId,
        string $projectId,
        int $amountCents,
        ?string $method = null,
        string $actor = 'artist',
    ): ?array;

    /**
     * @return array<string, mixed>|null
     */
    public function confirmDeposit(
        string $artistId,
        string $projectId,
        string $method = 'pix',
        ?string $externalRef = null,
        string $actor = 'artist',
    ): ?array;

    public function setStage(
        string $artistId,
        string $projectId,
        string $stageKey,
        string $actor = 'artist',
        ?string $at = null,
    ): void;

    /**
     * Import one historical message into the transcript. Deduplicates by WAHA
     * message id (or by content + timestamp when the id is absent).
     */
    public function importMessage(
        string $leadId,
        string $direction,
        string $text,
        ?string $waMessageId = null,
        ?string $at = null,
        bool $artist = false,
    ): bool;

    /**
     * Restore the Lead's historical timeline after a backfill.
     */
    public function setLeadTimeline(
        string $leadId,
        ?string $firstSeenAt = null,
        ?string $lastMessageAt = null,
    ): void;

    /**
     * Attribute an observed booking without using a non-event actor.
     */
    public function labelBooking(string $bookingId, string $label): void;

    /**
     * Backfilled past bookings must not be pushed to Google Calendar.
     */
    public function setBookingSync(string $bookingId, bool $pending): void;

    /**
     * Restore a historical booking's creation time and label.
     */
    public function setBookingCreatedAt(
        string $bookingId,
        ?string $createdAt,
        bool $sync,
        string $label = 'backfill',
    ): void;

    /**
     * Latest message time for the Lead, used to debounce summary generation.
     */
    public function latestMessageAt(string $leadId): ?string;

    public function upsertSummary(
        string $leadId,
        string $body,
        int $messageCount,
        ?string $model = null,
    ): void;
}
