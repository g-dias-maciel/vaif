<?php

namespace App\Services\Agent;

use App\Models\Note;
use App\Models\ObservedWrite;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Applies or rejects a review-queue item through the same RPCs the agent uses.
 *
 * Approving is always an explicit human decision. Until then the item has not
 * touched the domain.
 */
class ObservedWriteApplier
{
    public function __construct(
        private readonly AgentStore $store,
        private readonly ObservedStore $observed,
    ) {}

    /**
     * @return array{ok: bool, error?: string, result?: mixed}
     */
    public function approve(ObservedWrite $write, ?User $user = null): array
    {
        if (! $write->isPending()) {
            return ['ok' => false, 'error' => 'Este item já foi decidido.'];
        }

        try {
            $result = match ($write->kind) {
                ObservedWrite::KIND_BOOKING => $this->approveBooking($write),
                ObservedWrite::KIND_RESCHEDULE => $this->approveReschedule($write),
                ObservedWrite::KIND_QUOTE => $this->approveQuote($write),
                ObservedWrite::KIND_DEPOSIT_REQUEST => $this->approveDepositRequest($write),
                ObservedWrite::KIND_DEPOSIT_CONFIRM => $this->approveDepositConfirm($write),
                ObservedWrite::KIND_STAGE => $this->approveStage($write),
                ObservedWrite::KIND_NOTE => $this->approveNote($write, $user),
                default => ['ok' => false, 'error' => 'Tipo de item desconhecido.'],
            };
        } catch (\Throwable $e) {
            Log::warning('Observed write approval failed', [
                'write_id' => $write->id,
                'kind' => $write->kind,
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'error' => $e->getMessage()];
        }

        if (! ($result['ok'] ?? false)) {
            return $result;
        }

        $write->forceFill([
            'status' => ObservedWrite::STATUS_APPROVED,
            'decided_by' => $user?->id,
            'decided_at' => now(),
        ])->save();

        return $result;
    }

    public function reject(ObservedWrite $write, ?User $user = null): void
    {
        if (! $write->isPending()) {
            return;
        }

        $write->forceFill([
            'status' => ObservedWrite::STATUS_REJECTED,
            'decided_by' => $user?->id,
            'decided_at' => now(),
        ])->save();
    }

    /**
     * @return array{ok: bool, error?: string, result?: mixed}
     */
    private function approveBooking(ObservedWrite $write): array
    {
        $startAt = trim((string) ($write->payload['start_at'] ?? ''));

        if ($startAt === '' || $write->project_id === null) {
            return ['ok' => false, 'error' => 'Item sem data ou projeto.'];
        }

        $booking = $this->store->bookSlot($write->artist_id, $write->project_id, $startAt, 120, 'artist');

        if ($booking === null) {
            return ['ok' => false, 'error' => 'Horário indisponível ou ficha incompleta (local e região).'];
        }

        $this->observed->labelBooking((string) ($booking['id'] ?? ''), 'artist_approved');

        return ['ok' => true, 'result' => $booking];
    }

    /**
     * @return array{ok: bool, error?: string, result?: mixed}
     */
    private function approveReschedule(ObservedWrite $write): array
    {
        $startAt = trim((string) ($write->payload['start_at'] ?? ''));
        $bookingId = trim((string) ($write->payload['booking_id'] ?? ''));

        if ($startAt === '' || $bookingId === '') {
            return ['ok' => false, 'error' => 'Item sem data ou agendamento.'];
        }

        $booking = $this->store->reschedule($write->artist_id, $bookingId, $startAt);

        if ($booking === null) {
            return ['ok' => false, 'error' => 'Horário indisponível.'];
        }

        return ['ok' => true, 'result' => $booking];
    }

    /**
     * @return array{ok: bool, error?: string, result?: mixed}
     */
    private function approveQuote(ObservedWrite $write): array
    {
        $price = (int) ($write->payload['price_cents'] ?? 0);
        $base = (int) ($write->payload['base_cents'] ?? $price);

        if ($price <= 0 || $write->project_id === null) {
            return ['ok' => false, 'error' => 'Item sem valor ou projeto.'];
        }

        $quote = $this->observed->writeQuote(
            $write->artist_id,
            $write->project_id,
            max($base, $price),
            $price,
            'artist',
        );

        if ($quote === null) {
            return ['ok' => false, 'error' => 'Falta local/região na ficha para registrar o orçamento.'];
        }

        return ['ok' => true, 'result' => $quote];
    }

    /**
     * @return array{ok: bool, error?: string, result?: mixed}
     */
    private function approveDepositRequest(ObservedWrite $write): array
    {
        $amount = (int) ($write->payload['amount_cents'] ?? 0);

        if ($amount <= 0 || $write->project_id === null) {
            return ['ok' => false, 'error' => 'Item sem valor ou projeto.'];
        }

        $payment = $this->observed->requestDeposit(
            $write->artist_id,
            $write->project_id,
            $amount,
            'pix',
            'artist',
        );

        if ($payment === null) {
            return ['ok' => false, 'error' => 'É preciso ter um agendamento antes de pedir o sinal.'];
        }

        return ['ok' => true, 'result' => $payment];
    }

    /**
     * @return array{ok: bool, error?: string, result?: mixed}
     */
    private function approveDepositConfirm(ObservedWrite $write): array
    {
        if ($write->project_id === null) {
            return ['ok' => false, 'error' => 'Item sem projeto.'];
        }

        $payment = $this->observed->confirmDeposit(
            $write->artist_id,
            $write->project_id,
            (string) ($write->payload['method'] ?? 'pix'),
            isset($write->payload['external_ref']) ? (string) $write->payload['external_ref'] : null,
            'artist',
        );

        if ($payment === null) {
            return ['ok' => false, 'error' => 'Não há sinal pendente para confirmar.'];
        }

        return ['ok' => true, 'result' => $payment];
    }

    /**
     * @return array{ok: bool, error?: string, result?: mixed}
     */
    private function approveStage(ObservedWrite $write): array
    {
        $stage = trim((string) ($write->payload['stage_key'] ?? ''));

        if ($stage === '' || $write->project_id === null) {
            return ['ok' => false, 'error' => 'Item sem etapa ou projeto.'];
        }

        $this->observed->setStage($write->artist_id, $write->project_id, $stage, 'artist');

        return ['ok' => true];
    }

    /**
     * @return array{ok: bool, error?: string, result?: mixed}
     */
    private function approveNote(ObservedWrite $write, ?User $user): array
    {
        $text = trim((string) ($write->payload['text'] ?? ''));

        if ($text === '' || $write->lead_id === null || $user === null) {
            return ['ok' => false, 'error' => 'Nota sem texto ou autor.'];
        }

        Note::create([
            'lead_id' => $write->lead_id,
            'author_id' => $user->id,
            'body' => $text,
        ]);

        return ['ok' => true];
    }
}
