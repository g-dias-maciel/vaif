<?php

namespace App\Services\Agent;

use Illuminate\Support\Str;

/**
 * Code half of the grounded architecture: every claim must match a fact.
 *
 * Port of the n8n validator, now pure PHP and unit-testable.
 */
class ReplyValidator
{
    public function validate(array $facts, array $claims, string $reply, array $preflight = []): array
    {
        $pricing = $facts['pricing'] ?? [];
        $availability = $facts['availability'] ?? [];
        $project = $facts['project'] ?? [];
        $booking = $facts['booking'] ?? [];
        $conditions = $facts['conditions'] ?? [];
        $studio = $facts['studio'] ?? [];

        $isCover = in_array($project['tattoo_type'] ?? null, ['cover_up', 'rework'], true);
        $discoveryComplete = ! empty($project['tattoo_type'])
            && ! empty($project['placement'])
            && ! empty($project['body_zone'])
            && ! empty($project['style']);

        $allowedPrices = $this->allowedPrices($pricing);

        $slots = is_array($availability['slots'] ?? null) ? $availability['slots'] : [];
        $slotIds = array_column($slots, 'id');
        $tz = $studio['timezone'] ?? 'America/Sao_Paulo';
        $slotDates = [];
        foreach ($slots as $slot) {
            $day = $this->dateInTz($slot['start_at'] ?? null, $tz);
            if ($day !== null) {
                $slotDates[] = $day;
            }
        }
        $bookedStart = ! empty($booking['active']) && ! empty($booking['start_at'])
            ? $this->iso($booking['start_at'])
            : null;

        $intent = $facts['turn']['intent'] ?? 'other';
        $slotIntent = in_array($intent, ['ask_availability', 'choose_slot', 'close', 'reschedule'], true);

        $violations = [];
        $effectiveClaims = $claims;
        $offeredSlots = [];
        $hasPriceViolation = false;
        $hasDepositViolation = false;
        $hasConditionViolation = false;
        $hasPrematureSlot = false;
        $hasAvailabilityViolation = false;
        $hasBookingViolation = false;

        foreach ($claims as $claim) {
            if (! is_array($claim) || ! isset($claim['type'])) {
                continue;
            }

            switch ($claim['type']) {
                case 'price':
                    $cents = (int) ($claim['cents'] ?? 0);
                    if ($isCover) {
                        $violations[] = ['claim' => $claim, 'rule' => 'coverup_never_quotes'];
                        $hasPriceViolation = true;
                    } elseif (($pricing['status'] ?? null) !== 'ready') {
                        $violations[] = ['claim' => $claim, 'rule' => 'price_without_row', 'expected' => 'ready', 'actual' => $pricing['status'] ?? null];
                        $hasPriceViolation = true;
                    } elseif (! $discoveryComplete) {
                        $violations[] = ['claim' => $claim, 'rule' => 'price_before_qualification'];
                        $hasPriceViolation = true;
                    } elseif (! in_array($cents, $allowedPrices, true)) {
                        $violations[] = ['claim' => $claim, 'rule' => 'price_not_in_facts', 'expected' => $allowedPrices, 'actual' => $cents];
                        $hasPriceViolation = true;
                    }
                    break;

                case 'deposit':
                    $cents = (int) ($claim['cents'] ?? 0);
                    $expected = (int) ($pricing['deposit_cents'] ?? 0);
                    if ($expected <= 0 || $cents !== $expected) {
                        $violations[] = ['claim' => $claim, 'rule' => 'deposit_mismatch', 'expected' => $expected, 'actual' => $cents];
                        $hasDepositViolation = true;
                    }
                    if (empty($booking['active']) && empty($booking['created_this_turn'])) {
                        $violations[] = ['claim' => $claim, 'rule' => 'deposit_without_booking'];
                        $hasDepositViolation = true;
                    }
                    break;

                case 'slot_offer':
                    if (! $slotIntent) {
                        $violations[] = ['claim' => $claim, 'rule' => 'slot_before_availability_intent', 'expected' => 'ask_availability', 'actual' => $intent];
                        $hasAvailabilityViolation = true;
                        $hasPrematureSlot = true;
                        break;
                    }
                    $claimedStart = ! empty($claim['start_at']) ? $this->iso($claim['start_at']) : null;
                    if ($bookedStart !== null && $claimedStart === $bookedStart) {
                        $slot = $this->slotByStart($slots, $bookedStart);
                        if ($slot !== null) {
                            $offeredSlots[] = $slot;
                        }
                        break;
                    }
                    if (($availability['status'] ?? null) !== 'ready') {
                        $violations[] = ['claim' => $claim, 'rule' => 'availability_not_ready', 'expected' => 'ready', 'actual' => $availability['status'] ?? null];
                        $hasAvailabilityViolation = true;
                    } elseif (empty($claim['slot_id']) || ! in_array((string) $claim['slot_id'], $slotIds, true)) {
                        $violations[] = ['claim' => $claim, 'rule' => 'slot_not_offered', 'expected' => $slotIds, 'actual' => $claim['slot_id'] ?? $claim['start_at'] ?? null];
                        $hasAvailabilityViolation = true;
                    } else {
                        $slot = $this->slotById($slots, (string) $claim['slot_id']);
                        if ($slot !== null) {
                            $offeredSlots[] = $slot;
                        }
                    }
                    break;

                case 'day_offer':
                    if (! $slotIntent) {
                        $violations[] = ['claim' => $claim, 'rule' => 'slot_before_availability_intent', 'expected' => 'ask_availability', 'actual' => $intent];
                        $hasAvailabilityViolation = true;
                        $hasPrematureSlot = true;
                        break;
                    }
                    $day = substr((string) ($claim['date'] ?? ''), 0, 10);
                    $bookedDay = $bookedStart !== null ? $this->dateInTz($bookedStart, $tz) : null;
                    if (! in_array($day, $slotDates, true) && $day !== $bookedDay) {
                        $violations[] = ['claim' => $claim, 'rule' => 'day_without_slots', 'expected' => $slotDates, 'actual' => $day];
                        $hasAvailabilityViolation = true;
                    }
                    break;

                case 'no_availability':
                    if (($availability['status'] ?? null) !== 'no_slots') {
                        $violations[] = ['claim' => $claim, 'rule' => 'availability_exists', 'expected' => 'no_slots', 'actual' => $availability['status'] ?? null];
                        $hasAvailabilityViolation = true;
                    }
                    break;

                case 'booking_confirmed':
                    if (empty($booking['created_this_turn'])) {
                        $violations[] = ['claim' => $claim, 'rule' => 'booking_not_created'];
                        $hasBookingViolation = true;
                    }
                    break;

                case 'condition':
                    if (! $this->conditionValid($claim, $conditions, $pricing, $violations)) {
                        $hasConditionViolation = true;
                    }
                    break;

                case 'promise':
                    if (! $this->promiseValid($claim, $conditions, $pricing, $violations)) {
                        $hasConditionViolation = true;
                    }
                    break;

                case 'question':
                    $field = (string) ($claim['field'] ?? '');
                    $known = [
                        'type' => ! empty($project['tattoo_type']),
                        'placement' => ! empty($project['placement']),
                        'body_zone' => ! empty($project['body_zone']),
                        'style' => ! empty($project['style']),
                        'first_tattoo' => array_key_exists('first_tattoo', $project) && $project['first_tattoo'] !== null,
                        'meaning' => ! empty($project['meaning']),
                    ];
                    if (! in_array($field, ['name', 'photo', 'doubts', 'other'], true) && ($known[$field] ?? false)) {
                        $violations[] = ['claim' => $claim, 'rule' => 'question_known_field', 'expected' => $field, 'actual' => true];
                    }
                    break;

                case 'location':
                    $city = (string) ($studio['city'] ?? '');
                    if ($city === '' || ! str_contains($this->norm((string) ($claim['city'] ?? '')), $this->norm($city))) {
                        $violations[] = ['claim' => $claim, 'rule' => 'location_mismatch', 'expected' => $city, 'actual' => $claim['city'] ?? null];
                    }
                    break;
            }
        }

        if (count(array_filter($claims, fn ($c) => ($c['type'] ?? null) === 'slot_offer')) > 2) {
            $violations[] = ['claim' => ['type' => 'slot_offer'], 'rule' => 'too_many_slots', 'expected' => 2];
            $hasAvailabilityViolation = true;
        }

        $resolution = 'accepted';
        $finalReply = $reply;

        if ($violations !== []) {
            $canonicalCents = (int) ($pricing['offered_cents'] ?? $pricing['base_cents'] ?? $pricing['promotion']['price_cents'] ?? 0);

            if ($hasPriceViolation) {
                if ($isCover) {
                    // A cover-up is priced only after the artist sees the tattoo.
                    // Never let a hallucinated number reach the lead.
                    $finalReply = 'Para cobertura, preciso avaliar a tattoo antes de te passar o valor. Pode me enviar uma foto de como está hoje?';
                    $resolution = 'canonical_cover_up';
                    $effectiveClaims = [];
                    $offeredSlots = [];
                } elseif (($pricing['status'] ?? null) === 'ready' && $canonicalCents > 0) {
                    $finalReply = 'Perfeito! Fica '.Json::money($canonicalCents).' à vista.';
                    $resolution = 'canonical_price';
                    $effectiveClaims = [['type' => 'price', 'cents' => $canonicalCents]];
                    $offeredSlots = [];
                } else {
                    // No trustworthy price row: defer to the artist instead of
                    // sending whatever the model invented.
                    $finalReply = 'Vou confirmar o valor com o artista e já te respondo.';
                    $resolution = 'canonical_price_unknown';
                    $effectiveClaims = [];
                    $offeredSlots = [];
                }
            } elseif ($hasDepositViolation) {
                $deposit = (int) ($pricing['deposit_cents'] ?? 0);
                if ($deposit > 0 && (! empty($booking['active']) || ! empty($booking['created_this_turn']))) {
                    $finalReply = 'O sinal fica '.Json::money($deposit).' no PIX.';
                    $effectiveClaims = [['type' => 'deposit', 'cents' => $deposit]];
                } else {
                    $finalReply = 'Antes do sinal, precisamos confirmar o horário. Qual dia funciona para você?';
                    $effectiveClaims = [];
                }
                $resolution = 'canonical_deposit';
                $offeredSlots = [];
            } elseif ($hasConditionViolation) {
                $keys = array_map(fn ($c) => (string) ($c['key'] ?? ''), array_filter($claims, fn ($c) => in_array($c['type'] ?? null, ['promise', 'condition'], true)));
                if (in_array('hold_slot', $keys, true)) {
                    $finalReply = 'Não consigo segurar o horário sem o sinal. Quer seguir com a reserva?';
                } elseif (in_array('guarantee_price', $keys, true) || in_array('discount', $keys, true)) {
                    $finalReply = $canonicalCents > 0
                        ? 'Não consigo garantir esse valor além do combinado. O valor atual é '.Json::money($canonicalCents).'.'
                        : 'Não consigo garantir esse valor além do combinado.';
                } elseif (in_array('payment', $keys, true)) {
                    $finalReply = 'Vou confirmar as formas de pagamento com o artista.';
                } else {
                    $finalReply = 'Vou confirmar essa política com o artista antes de te responder.';
                }
                $resolution = 'canonical_condition';
                $offeredSlots = [];
                $effectiveClaims = [];
            } elseif ($hasPrematureSlot) {
                $finalReply = 'Antes de horários, me conta mais sobre a tatuagem que você quer fazer?';
                $resolution = 'canonical_premature_slots';
                $offeredSlots = [];
                $effectiveClaims = [];
            } elseif ($hasAvailabilityViolation || $hasBookingViolation) {
                if (($availability['status'] ?? null) === 'ready' && $slots !== []) {
                    $labels = implode(' ou ', array_map(fn ($s) => $s['label'] ?? '', array_slice($slots, 0, 2)));
                    $finalReply = 'Tenho estes horários: '.$labels.'. Qual fica melhor para você?';
                    $resolution = 'canonical_slots';
                    $offeredSlots = array_slice($slots, 0, 2);
                } elseif (($availability['status'] ?? null) === 'no_slots') {
                    $finalReply = 'Não tenho horário nesse dia. Qual outro dia funciona para você?';
                    $resolution = 'canonical_no_slots';
                } else {
                    $missing = (string) ($availability['missing'] ?? '');
                    if (str_contains($missing, 'local')) {
                        $finalReply = 'Onde no corpo você quer fazer?';
                    } elseif (str_contains($missing, 'tamanho')) {
                        $finalReply = 'Nesse local, você quer uma tattoo pequena, uma área média ou o local inteiro?';
                    } elseif (str_contains($missing, 'tipo')) {
                        $finalReply = 'A tatuagem que você quer é nova, uma cobertura ou uma reforma?';
                    } else {
                        $finalReply = 'Me conta mais sobre a tatuagem que você quer fazer?';
                    }
                    $resolution = 'canonical_missing';
                }
                $effectiveClaims = [];
                if (in_array($resolution, ['canonical_no_slots', 'canonical_missing'], true)) {
                    $offeredSlots = [];
                }
            } else {
                $resolution = 'kept_legacy';
            }
        }

        if (! empty($booking['created_this_turn'])) {
            $offeredSlots = [];
        }

        $offeredSlots = $this->dedupeSlots($offeredSlots);
        $priceClaims = array_filter($effectiveClaims, fn ($c) => ($c['type'] ?? null) === 'price');
        $needsQuote = $resolution === 'canonical_price'
            || ($priceClaims !== [] && empty($pricing['open_quote']) && $resolution !== 'kept_legacy');

        return [
            'ok' => $violations === [],
            'final_reply' => $finalReply,
            'violations' => $violations,
            'resolution' => $resolution,
            'needs_quote' => $needsQuote,
            'offered_slots' => $offeredSlots,
            'effective_claims' => array_values($effectiveClaims),
            'original_reply' => $reply,
            'repairable' => $violations !== [] && $resolution !== 'kept_legacy',
        ];
    }

    /**
     * @return array<int, int>
     */
    private function allowedPrices(array $pricing): array
    {
        $values = [
            $pricing['base_cents'] ?? null,
            $pricing['offered_cents'] ?? null,
            $pricing['promotion']['price_cents'] ?? null,
        ];

        return array_values(array_unique(array_filter(array_map('intval', $values), fn ($v) => $v > 0)));
    }

    private function conditionValid(array $claim, array $conditions, array $pricing, array &$violations): bool
    {
        $key = (string) ($claim['key'] ?? '');
        $value = (string) ($claim['value'] ?? '');
        $digits = (int) preg_replace('/\D/', '', $value);
        $methods = is_array($conditions['payment_methods'] ?? null) ? $conditions['payment_methods'] : [];
        $normalized = $this->norm($value);

        $valid = match ($key) {
            'deposit' => ($digits > 0 && ($digits === (int) ($conditions['deposit_value'] ?? 0) || $digits === (int) ($pricing['deposit_cents'] ?? 0)))
                || ($normalized !== '' && str_contains($normalized, 'pix') && ! empty($conditions['pix_available'])),
            'reschedule' => ($conditions['reschedule_hours'] ?? null) !== null && $digits === (int) $conditions['reschedule_hours'],
            'cancellation' => ($conditions['cancellation_hours'] ?? null) !== null && $digits === (int) $conditions['cancellation_hours'],
            'payment' => (str_contains($normalized, 'pix') && (! empty($conditions['pix_available']) || in_array('pix', $methods, true)))
                || (preg_match('/(cartao|credito|debito)/', $normalized) && (in_array('credit_card', $methods, true) || in_array('debit_card', $methods, true)))
                || (preg_match('/(dinheiro|especie)/', $normalized) && in_array('cash', $methods, true))
                || (str_contains($normalized, 'parcel') && in_array('installments', $methods, true)),
            default => false,
        };

        if (! $valid) {
            $violations[] = ['claim' => $claim, 'rule' => 'condition_not_in_facts', 'expected' => $key, 'actual' => $value];
        }

        return $valid;
    }

    private function promiseValid(array $claim, array $conditions, array $pricing, array &$violations): bool
    {
        $key = (string) ($claim['key'] ?? '');
        $valid = match ($key) {
            'hold_slot' => ! empty($conditions['allow_hold_slot']),
            'guarantee_price' => ! empty($conditions['guarantee_price']),
            'discount' => (int) ($pricing['offered_cents'] ?? 0) < (int) ($pricing['base_cents'] ?? 0),
            default => false,
        };

        if (! $valid) {
            $violations[] = ['claim' => $claim, 'rule' => 'promise_not_allowed', 'expected' => $key, 'actual' => true];
        }

        return $valid;
    }

    private function norm(?string $value): string
    {
        return Str::ascii(mb_strtolower(trim((string) $value)));
    }

    private function iso(mixed $value): ?string
    {
        try {
            return (new \DateTimeImmutable((string) $value))->format('c');
        } catch (\Throwable) {
            return null;
        }
    }

    private function dateInTz(mixed $value, string $tz): ?string
    {
        try {
            return (new \DateTimeImmutable((string) $value))->setTimezone(new \DateTimeZone($tz))->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $slots
     */
    private function slotById(array $slots, string $id): ?array
    {
        foreach ($slots as $slot) {
            if ((string) ($slot['id'] ?? '') === $id) {
                return $slot;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $slots
     */
    private function slotByStart(array $slots, string $start): ?array
    {
        foreach ($slots as $slot) {
            if ($this->iso($slot['start_at'] ?? null) === $start) {
                return $slot;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $slots
     * @return array<int, array<string, mixed>>
     */
    private function dedupeSlots(array $slots): array
    {
        $seen = [];
        $out = [];

        foreach ($slots as $slot) {
            $id = (string) ($slot['id'] ?? '');
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $out[] = $slot;
        }

        return $out;
    }
}
