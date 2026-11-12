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
        $hasKnownFieldQuestion = false;
        $hasTimeViolation = false;
        $hasSpamQuestion = false;
        $hasCoverupQuestion = false;
        $hasPromotionViolation = false;
        $hasAftercareViolation = false;
        $hasBookingBeforeDeposit = false;
        $hasCoverupNoHandoff = false;
        $hasCoverupRefusal = false;
        $hasCoverupRefusalIgnored = false;
        $hasPhotoReasked = false;
        $hasBusyQuestion = false;
        $flags = is_array($preflight['flags'] ?? null) ? $preflight['flags'] : [];
        $isAftercare = $intent === 'aftercare' || ! empty($flags['is_aftercare']);
        $coverHint = ! empty($flags['mentions_coverup']);
        $coverCase = $isCover || $coverHint;
        $hasHandoff = false;

        foreach ($claims as $claim) {
            if (! is_array($claim) || ! isset($claim['type'])) {
                continue;
            }

            switch ($claim['type']) {
                case 'price':
                    $cents = (int) ($claim['cents'] ?? 0);
                    if ($coverCase) {
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

                    // Choosing a slot pre-reserves it. The appointment is only
                    // confirmed after the deposit lands. Without any booking,
                    // the generic booking_not_created path handles it.
                    if ((! empty($booking['active']) || ! empty($booking['created_this_turn']))
                        && ($booking['deposit_status'] ?? '') !== 'confirmado') {
                        $violations[] = ['claim' => $claim, 'rule' => 'booking_confirmed_before_deposit'];
                        $hasBookingBeforeDeposit = true;
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
                        $hasKnownFieldQuestion = true;
                    }

                    if ($intent === 'spam' && in_array($field, ['name', 'type', 'placement', 'body_zone', 'style', 'first_tattoo', 'meaning', 'photo'], true)) {
                        $violations[] = ['claim' => $claim, 'rule' => 'spam_qualification', 'expected' => 'close', 'actual' => $field];
                        $hasSpamQuestion = true;
                    }

                    // For a cover-up, the local/size of the CURRENT tattoo are
                    // useful for the artist; the new tattoo is never qualified.
                    if ($coverCase && in_array($field, ['type', 'style', 'first_tattoo', 'meaning'], true)) {
                        $violations[] = ['claim' => $claim, 'rule' => 'coverup_discovery', 'expected' => 'photo', 'actual' => $field];
                        $hasCoverupQuestion = true;
                    }
                    break;

                case 'handoff':
                    $hasHandoff = true;
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

        // The claim extractor misses conversational promises ("já deixei
        // salvo", "pode deixar que eu seguro"). These are exactly the phrases
        // the artist uses, so scan the final text as a second safety net.
        $hasBooking = ! empty($booking['active']) || ! empty($booking['created_this_turn']);
        $normalizedReply = $this->norm($reply);

        if (! $hasBooking && empty($conditions['allow_hold_slot']) && $this->promisesHold($normalizedReply)) {
            $violations[] = ['claim' => ['type' => 'promise', 'key' => 'hold_slot'], 'rule' => 'promise_not_allowed', 'expected' => 'hold_slot', 'actual' => true];
            $hasConditionViolation = true;
        }

        if (empty($conditions['guarantee_price'])) {
            foreach (['mantenho o valor', 'mantenho esse valor', 'mantenho o preco', 'congelei o valor', 'garanto o valor', 'valor garantido'] as $phrase) {
                if (str_contains($normalizedReply, $phrase)
                    && ! str_contains($normalizedReply, 'nao '.$phrase)
                    && ! str_contains($normalizedReply, 'nunca '.$phrase)) {
                    $violations[] = ['claim' => ['type' => 'promise', 'key' => 'guarantee_price'], 'rule' => 'promise_not_allowed', 'expected' => 'guarantee_price', 'actual' => $phrase];
                    $hasConditionViolation = true;
                    break;
                }
            }
        }

        if (! $hasBooking && $this->hasTimeOutsideHours($reply, $studio)) {
            $violations[] = ['claim' => ['type' => 'time'], 'rule' => 'time_outside_hours', 'expected' => $studio['working_hours'] ?? [], 'actual' => $reply];
            $hasTimeViolation = true;
        }

        if (! empty($flags['mentions_promotion'])
            && empty($pricing['promotion']['price_cents'])
            && ! $this->mentionsConfirmation($reply)) {
            $violations[] = ['claim' => ['type' => 'promotion'], 'rule' => 'promotion_not_confirmed'];
            $hasPromotionViolation = true;
        }

        if ($isAftercare && ! $hasHandoff) {
            $violations[] = ['claim' => ['type' => 'handoff'], 'rule' => 'aftercare_without_handoff'];
            $hasAftercareViolation = true;
        }

        // Cover-up is a handoff case. Asking for the photos is fine; never
        // leaving the case with the artist is not.
        if ($coverCase && ! $hasHandoff && ! $hasPriceViolation && ! $hasConditionViolation) {
            $violations[] = ['claim' => ['type' => 'handoff'], 'rule' => 'coverup_without_handoff'];
            $hasCoverupNoHandoff = true;
        }

        // The lead already said cover-up is not an option. Offering it again
        // is a repeated question, even if the project type is still empty.
        if (! empty($flags['coverup_refused'])
            && str_contains($reply, '?')
            && str_contains($this->norm($reply), 'cobrir')) {
            $violations[] = ['claim' => ['type' => 'question', 'field' => 'type'], 'rule' => 'coverup_reasked_after_refusal'];
            $hasCoverupRefusal = true;
        }

        if (! empty($flags['coverup_refused']) && ! $hasCoverupRefusal) {
            $norm = $this->norm($reply);
            $acknowledged = preg_match('/(nao da|nao e possivel|nao rola|nao funciona|descart)/u', $norm) === 1;

            if (! $acknowledged) {
                $violations[] = ['claim' => ['type' => 'question', 'field' => 'type'], 'rule' => 'coverup_refusal_ignored'];
                $hasCoverupRefusalIgnored = true;
            }
        }

        if (! empty($flags['lead_busy'])) {
            foreach ($claims as $claim) {
                if (is_array($claim) && ($claim['type'] ?? null) === 'question') {
                    $violations[] = ['claim' => $claim, 'rule' => 'question_while_busy'];
                    $hasBusyQuestion = true;
                    break;
                }
            }
        }

        // The lead already sent the current-tattoo photo. Asking for it again
        // is the most common calibration miss in cover-up conversations.
        if ($coverCase
            && ! empty($flags['lead_photos'])
            && str_contains($reply, '?')
            && preg_match('/(foto|fotos)[^?]{0,45}(tatuagem atual|tattoo atual|como esta|do local|do braco|do antebraco)|(tatuagem atual|tattoo atual)[^?]{0,45}(foto|fotos)/u', $this->norm($reply)) === 1) {
            $violations[] = ['claim' => ['type' => 'question', 'field' => 'photo'], 'rule' => 'photo_reasked'];
            $hasPhotoReasked = true;
        }

        // Deterministic known-field guard. The claim extractor sometimes
        // labels a compound question as `other` and misses the repeat.
        if ($this->asksKnownField($reply, $project)) {
            $violations[] = ['claim' => ['type' => 'question', 'field' => 'known'], 'rule' => 'question_known_field', 'expected' => 'known', 'actual' => true];
            $hasKnownFieldQuestion = true;
        }

        $resolution = 'accepted';
        $finalReply = $reply;

        if ($violations !== []) {
            $canonicalCents = (int) ($pricing['offered_cents'] ?? $pricing['base_cents'] ?? $pricing['promotion']['price_cents'] ?? 0);

            if ($hasPriceViolation) {
                if ($coverCase) {
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
            } elseif ($hasBookingBeforeDeposit) {
                $finalReply = 'Seu horário ficou pré-reservado, mas ele só é confirmado depois do sinal. Te passo o PIX agora?';
                $resolution = 'canonical_booking_pending_deposit';
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
            } elseif ($hasSpamQuestion) {
                $finalReply = 'Acho que essa mensagem veio para o número errado. Por aqui o estúdio trabalha só com tatuagem. Se precisar, é só chamar!';
                $resolution = 'canonical_spam';
                $offeredSlots = [];
                $effectiveClaims = [];
            } elseif ($hasCoverupRefusal || $hasCoverupRefusalIgnored) {
                $artistName = (string) ($studio['artist_name'] ?? 'o artista');
                $finalReply = 'Entendi, cobrir essa atual não vai dar certo mesmo. Então a ideia é uma tatuagem nova ao lado dela. Me conta o tamanho e o estilo que você imagina; vou passar para '.$artistName.' avaliar o espaço e te explicar a cobertura.';
                $resolution = 'canonical_coverup_refused';
                $offeredSlots = [];
                $effectiveClaims = [];
            } elseif ($hasBusyQuestion) {
                $artistName = (string) ($studio['artist_name'] ?? 'o artista');
                $portfolio = (string) ($facts['portfolio']['url'] ?? '');
                $finalReply = 'Tudo bem, sem pressa!'
                    .($portfolio !== '' ? ' Dá uma olhada no portfólio do '.$artistName.': '.$portfolio.'.' : '')
                    .' Quando estiver livre, me chama que a gente agenda; no dia o '.$artistName.' monta a arte com você.';
                $resolution = 'canonical_busy';
                $offeredSlots = [];
                $effectiveClaims = [];
            } elseif ($hasPhotoReasked || $hasCoverupQuestion || $hasCoverupNoHandoff) {
                $finalReply = $this->coverupReply($facts, $studio, $flags);
                $resolution = 'canonical_cover_up';
                $offeredSlots = [];
                $effectiveClaims = [];
            } elseif ($hasPromotionViolation) {
                $finalReply = 'Vou confirmar essa oferta com o artista e já te respondo.';
                $resolution = 'canonical_promotion';
                $offeredSlots = [];
                $effectiveClaims = [];
            } elseif ($hasAftercareViolation) {
                $finalReply = 'Sinto muito que esteja assim. Como não sou médica, o ideal é procurar um médico ou uma UBS. Vou passar seu caso para o Adriano agora.';
                $resolution = 'canonical_aftercare';
                $offeredSlots = [];
                $effectiveClaims = [];
            } elseif ($hasKnownFieldQuestion) {
                // Re-asking a field the lead already answered is never sent.
                if ($coverCase) {
                    $finalReply = $this->coverupReply($facts, $studio, $flags);
                    $resolution = 'canonical_cover_up';
                } else {
                    $finalReply = $this->nextDiscoveryQuestion($project)
                        ?? 'Me conta mais sobre a tatuagem que você quer fazer?';
                    $resolution = 'canonical_missing';
                }
                $offeredSlots = [];
                $effectiveClaims = [];
            } elseif ($hasTimeViolation) {
                $finalReply = 'Esse horário está fora do funcionamento do estúdio. Me conta o dia e o período que funciona para você, que eu vejo a agenda com o artista.';
                $resolution = 'canonical_time';
                $offeredSlots = [];
                $effectiveClaims = [];
            } else {
                // No deterministic repair exists for this rule. Never let the
                // original text through in auto mode: defer to the artist.
                $finalReply = 'Vou confirmar isso com o artista e já te respondo.';
                $resolution = 'canonical_defer';
                $offeredSlots = [];
                $effectiveClaims = [];
            }
        }

        if (! empty($booking['created_this_turn'])) {
            $offeredSlots = [];
        }

        $offeredSlots = $this->dedupeSlots($offeredSlots);
        $priceClaims = array_filter($effectiveClaims, fn ($c) => ($c['type'] ?? null) === 'price');
        $needsQuote = $resolution === 'canonical_price'
            || ($priceClaims !== [] && empty($pricing['open_quote'])
                && ! in_array($resolution, ['kept_legacy', 'canonical_defer'], true));

        return [
            'ok' => $violations === [],
            'final_reply' => $finalReply,
            'violations' => $violations,
            'resolution' => $resolution,
            'needs_quote' => $needsQuote,
            'offered_slots' => $offeredSlots,
            'effective_claims' => array_values($effectiveClaims),
            'original_reply' => $reply,
            // Give the composer one chance to turn a deterministic repair into
            // a natural message. The repaired text is validated again, so a
            // bad rewrite falls back to the canonical safe reply.
            'repairable' => $violations !== [],
        ];
    }

    /**
     * The reply acknowledges that a promotion/offer needs checking instead of
     * confirming or ignoring it.
     */
    private function mentionsConfirmation(string $reply): bool
    {
        $normalized = $this->norm($reply);

        foreach (['confirm', 'verificar', 'checar', 'nao tenho', 'nao consigo', 'nao esta ativa', 'nao encontrei'] as $phrase) {
            if (str_contains($normalized, $phrase)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Affirmative hold language, ignoring the negative form the agent uses
     * when it explains that a slot cannot be held without a deposit.
     */
    private function promisesHold(string $normalized): bool
    {
        foreach ([
            'deixei salvo',
            'deixo salvo',
            'deixar salvo',
            'reservei',
            'segurei o horario',
            'seguro o horario',
            'vou segurar o horario',
            'garanti o horario',
        ] as $phrase) {
            if (str_contains($normalized, $phrase)) {
                return true;
            }
        }

        foreach (['esta reservado', 'ta reservado', 'horario reservado'] as $phrase) {
            if (str_contains($normalized, $phrase)
                && ! str_contains($normalized, 'nao '.$phrase)
                && ! str_contains($normalized, 'nunca '.$phrase)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The safe cover-up reply: photos if none, video if photos already sent,
     * plus the handoff. Uses the artist's real name when available.
     */
    private function coverupReply(array $facts, array $studio, array $flags): string
    {
        $artistName = (string) ($studio['artist_name'] ?? 'o artista');
        $photos = ! empty($flags['lead_photos'])
            || ! empty($facts['turn']['agent_state']['photo_confirmed']);

        return $photos
            ? 'Recebi as fotos. Vou passar para '.$artistName.' avaliar a tatuagem atual e montar a proposta. Se puder, me manda também um vídeo curto parando 3 a 4 segundos em cada posição do local.'
            : 'Consigo avaliar a cobertura, sim. Me manda fotos da tatuagem atual do local inteiro e de perto, com boa luz; se puder, um vídeo curto parando 3 a 4 segundos em cada posição também ajuda. Vou passar para '.$artistName.' avaliar e montar a proposta.';
    }

    /**
     * Deterministic known-field guard for compound questions the claim
     * extractor may label as `other`.
     */
    private function asksKnownField(string $reply, array $project): bool
    {
        if (! str_contains($reply, '?')) {
            return false;
        }

        $norm = $this->norm($reply);

        if (! empty($project['body_zone'])
            && preg_match('/(qual o tamanho|que tamanho|tamanho aproximado|pequena, uma area media|uma area media ou o local inteiro)/u', $norm) === 1) {
            return true;
        }

        if (! empty($project['placement'])
            && preg_match('/(onde no corpo|em que parte do corpo|qual o local)/u', $norm) === 1) {
            return true;
        }

        if (! empty($project['tattoo_type'])
            && preg_match('/(nova, uma cobertura ou uma reforma|nova, cobertura ou reforma)/u', $norm) === 1) {
            return true;
        }

        return false;
    }

    /**
     * The next discovery field, in the order the methodology requires.
     */
    private function nextDiscoveryQuestion(array $project): ?string
    {
        if (empty($project['tattoo_type'])) {
            return 'A tatuagem que você quer é nova, uma cobertura ou uma reforma?';
        }

        if (in_array($project['tattoo_type'], ['cover_up', 'rework'], true)) {
            return 'Pode me enviar uma foto da tatuagem atual que você quer cobrir ou reformar?';
        }

        if (empty($project['placement'])) {
            return 'Onde no corpo você quer fazer?';
        }

        if (empty($project['body_zone'])) {
            return 'Nesse local, você quer uma tattoo pequena, uma área média ou o local inteiro?';
        }

        if (empty($project['style'])) {
            return 'Qual estilo você tem em mente?';
        }

        if (! array_key_exists('first_tattoo', $project) || $project['first_tattoo'] === null) {
            return 'É a sua primeira tatuagem?';
        }

        if (empty($project['meaning'])) {
            return 'Tem algum significado ou referência para essa tattoo?';
        }

        return null;
    }

    /**
     * A time that appears in the reply but fits no working window. Catches
     * "pode vir 18h20" when the studio closes at 18h.
     */
    private function hasTimeOutsideHours(string $reply, array $studio): bool
    {
        $hours = is_array($studio['working_hours'] ?? null) ? $studio['working_hours'] : [];
        if ($hours === []) {
            return false;
        }

        preg_match_all('/\b([01]?\d|2[0-3])[:h]([0-5]\d)?\b/u', $reply, $matches, PREG_SET_ORDER);
        if ($matches === []) {
            return false;
        }

        $windows = [];
        foreach ($hours as $ranges) {
            if (! is_array($ranges)) {
                continue;
            }
            foreach ($ranges as $range) {
                if (preg_match('/^(\d{1,2}):(\d{2})-(\d{1,2}):(\d{2})$/', (string) $range, $m) === 1) {
                    $windows[] = [(int) $m[1] * 60 + (int) $m[2], (int) $m[3] * 60 + (int) $m[4]];
                }
            }
        }

        if ($windows === []) {
            return false;
        }

        foreach ($matches as $match) {
            $minutes = (int) $match[1] * 60 + (int) ($match[2] ?? 0);
            $inside = false;

            foreach ($windows as [$start, $end]) {
                if ($minutes >= $start && $minutes <= $end) {
                    $inside = true;
                    break;
                }
            }

            if (! $inside) {
                return true;
            }
        }

        return false;
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
