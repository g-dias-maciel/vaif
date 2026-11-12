<?php

namespace Tests\Feature;

use App\Services\Agent\ReplyValidator;
use Tests\TestCase;

class ReplyValidatorTest extends TestCase
{
    private function facts(array $overrides = []): array
    {
        return array_replace_recursive([
            'pricing' => [
                'status' => 'ready',
                'base_cents' => 60000,
                'offered_cents' => 60000,
                'deposit_cents' => 18000,
                'open_quote' => false,
                'promotion' => null,
            ],
            'availability' => ['status' => 'ready', 'missing' => null, 'slots' => []],
            'project' => [
                'tattoo_type' => 'new',
                'placement' => 'braço',
                'body_zone' => 'área média',
                'style' => 'fine line',
                'first_tattoo' => true,
                'meaning' => null,
            ],
            'booking' => ['active' => false, 'start_at' => null, 'created_this_turn' => false],
            'conditions' => [
                'pix_available' => true,
                'reschedule_hours' => 48,
                'cancellation_hours' => 48,
                'payment_methods' => ['pix', 'credit_card', 'cash'],
                'allow_hold_slot' => false,
                'guarantee_price' => false,
                'deposit_value' => 30,
            ],
            'client' => ['returning' => false],
            'studio' => ['city' => 'São Paulo', 'timezone' => 'America/Sao_Paulo'],
            'turn' => ['intent' => 'ask_price'],
        ], $overrides);
    }

    public function test_an_exact_price_is_accepted(): void
    {
        $result = (new ReplyValidator())->validate(
            $this->facts(),
            [['type' => 'price', 'cents' => 60000]],
            'Fica R$ 600,00.',
        );

        $this->assertTrue($result['ok']);
        $this->assertSame('accepted', $result['resolution']);
        $this->assertSame('Fica R$ 600,00.', $result['final_reply']);
    }

    public function test_a_price_before_full_discovery_is_repaired_by_the_canonical_price(): void
    {
        $result = (new ReplyValidator())->validate(
            $this->facts(['project' => ['body_zone' => null]]),
            [['type' => 'price', 'cents' => 60000]],
            'Fica R$ 600,00.',
        );

        $this->assertFalse($result['ok']);
        $this->assertSame('canonical_price', $result['resolution']);
        $this->assertSame('price_before_qualification', $result['violations'][0]['rule']);
    }

    public function test_cover_up_price_is_replaced_with_a_photo_request(): void
    {
        $result = (new ReplyValidator())->validate(
            $this->facts(['project' => ['tattoo_type' => 'cover_up']]),
            [['type' => 'price', 'cents' => 60000]],
            'Fica R$ 600,00.',
        );

        $this->assertFalse($result['ok']);
        $this->assertSame('canonical_cover_up', $result['resolution']);
        $this->assertStringContainsString('avaliar', $result['final_reply']);
        $this->assertStringNotContainsString('R$', $result['final_reply']);
    }

    public function test_two_slots_are_the_maximum_offered(): void
    {
        $slots = [
            ['id' => 's1', 'label' => 'terça 14h', 'start_at' => '2026-11-10T17:00:00+00:00'],
            ['id' => 's2', 'label' => 'quarta 10h', 'start_at' => '2026-11-11T13:00:00+00:00'],
            ['id' => 's3', 'label' => 'quinta 16h', 'start_at' => '2026-11-12T19:00:00+00:00'],
        ];

        $result = (new ReplyValidator())->validate(
            $this->facts([
                'availability' => ['status' => 'ready', 'missing' => null, 'slots' => $slots],
                'turn' => ['intent' => 'ask_availability'],
            ]),
            [
                ['type' => 'slot_offer', 'slot_id' => 's1'],
                ['type' => 'slot_offer', 'slot_id' => 's2'],
                ['type' => 'slot_offer', 'slot_id' => 's3'],
            ],
            'Tenho terça, quarta e quinta.',
        );

        $this->assertFalse($result['ok']);
        $this->assertSame(2, count($result['offered_slots']));
    }

    public function test_an_invented_reschedule_window_is_blocked(): void
    {
        $result = (new ReplyValidator())->validate(
            $this->facts(['turn' => ['intent' => 'ask_policy']]),
            [['type' => 'condition', 'key' => 'reschedule', 'value' => '7 dias']],
            'Pode remarcar até 7 dias antes.',
        );

        $this->assertFalse($result['ok']);
        $this->assertSame('canonical_condition', $result['resolution']);
        $this->assertSame('condition_not_in_facts', $result['violations'][0]['rule']);
    }

    public function test_promising_to_hold_a_slot_without_a_deposit_is_blocked(): void
    {
        $result = (new ReplyValidator())->validate(
            $this->facts(['turn' => ['intent' => 'ask_availability']]),
            [['type' => 'promise', 'key' => 'hold_slot']],
            'Pode deixar, seguro o horário para você.',
        );

        $this->assertFalse($result['ok']);
        $this->assertSame('promise_not_allowed', $result['violations'][0]['rule']);
        $this->assertStringContainsString('sem o sinal', $result['final_reply']);
    }

    public function test_explaining_that_a_slot_cannot_be_held_is_not_a_promise(): void
    {
        $result = (new ReplyValidator())->validate(
            $this->facts(),
            [],
            'Não consigo segurar o horário sem o sinal. O horário só fica reservado depois do pagamento.',
        );

        $this->assertTrue($result['ok']);
    }

    public function test_a_conversational_hold_phrase_is_blocked_without_a_claim(): void
    {
        $result = (new ReplyValidator())->validate(
            $this->facts(),
            [],
            'Show, já deixei salvo o seu horário.',
        );

        $this->assertFalse($result['ok']);
        $this->assertSame('promise_not_allowed', $result['violations'][0]['rule']);
        $this->assertSame('canonical_condition', $result['resolution']);
        $this->assertStringNotContainsString('deixei salvo', $result['final_reply']);
    }

    public function test_a_confirmation_question_for_a_missing_field_is_accepted(): void
    {
        $result = (new ReplyValidator())->validate(
            $this->facts(['project' => ['tattoo_type' => null]]),
            [['type' => 'question', 'field' => 'type']],
            'Essa tatuagem seria uma tatuagem nova, certo?',
            ['flags' => []],
        );

        $this->assertTrue($result['ok']);
        $this->assertSame('accepted', $result['resolution']);
    }

    public function test_re_asking_a_known_field_is_replaced_with_the_next_missing_question(): void
    {
        $result = (new ReplyValidator())->validate(
            $this->facts(),
            [['type' => 'question', 'field' => 'type']],
            'A tatuagem que você quer é nova, cobertura ou reforma?',
        );

        $this->assertFalse($result['ok']);
        $this->assertSame('question_known_field', $result['violations'][0]['rule']);
        $this->assertSame('canonical_missing', $result['resolution']);
        $this->assertStringContainsString('significado', $result['final_reply']);
    }

    public function test_spam_does_not_ask_a_qualification_question(): void
    {
        $result = (new ReplyValidator())->validate(
            $this->facts(['turn' => ['intent' => 'spam']]),
            [['type' => 'question', 'field' => 'name']],
            'Aqui é da Biofisic? Qual o seu nome?',
            ['flags' => []],
        );

        $this->assertFalse($result['ok']);
        $this->assertSame('spam_qualification', $result['violations'][0]['rule']);
        $this->assertSame('canonical_spam', $result['resolution']);
        $this->assertStringNotContainsString('nome', $result['final_reply']);
    }

    public function test_cover_up_does_not_ask_style_again(): void
    {
        $result = (new ReplyValidator())->validate(
            $this->facts(['project' => ['tattoo_type' => 'cover_up']]),
            [['type' => 'question', 'field' => 'style']],
            'Qual estilo você quer para essa cobertura?',
            ['flags' => []],
        );

        $this->assertFalse($result['ok']);
        $this->assertContains('coverup_discovery', array_column($result['violations'], 'rule'));
        $this->assertSame('canonical_cover_up', $result['resolution']);
        $this->assertStringContainsString('foto', $result['final_reply']);
    }

    public function test_cover_up_may_ask_the_current_tattoo_location_and_size(): void
    {
        $result = (new ReplyValidator())->validate(
            $this->facts([
                'project' => ['tattoo_type' => 'cover_up', 'placement' => null, 'body_zone' => null],
            ]),
            [['type' => 'question', 'field' => 'placement'], ['type' => 'handoff']],
            'Onde fica a tatuagem atual que você quer cobrir? Vou passar para o Adriano avaliar.',
            ['flags' => []],
        );

        $this->assertTrue($result['ok']);
    }

    public function test_a_mentioned_promotion_must_be_confirmed(): void
    {
        $result = (new ReplyValidator())->validate(
            $this->facts(['pricing' => ['promotion' => null]]),
            [['type' => 'question', 'field' => 'style']],
            'Legal! Qual estilo você quer?',
            ['flags' => ['mentions_promotion' => true]],
        );

        $this->assertFalse($result['ok']);
        $this->assertContains('promotion_not_confirmed', array_column($result['violations'], 'rule'));
        $this->assertSame('canonical_promotion', $result['resolution']);
        $this->assertStringContainsString('confirmar', $result['final_reply']);
    }

    public function test_confirming_the_promotion_is_accepted(): void
    {
        $result = (new ReplyValidator())->validate(
            $this->facts(['pricing' => ['promotion' => null]]),
            [],
            'Vou confirmar essa oferta com o artista e já te respondo.',
            ['flags' => ['mentions_promotion' => true]],
        );

        $this->assertTrue($result['ok']);
    }

    public function test_aftercare_without_a_handoff_is_replaced(): void
    {
        $result = (new ReplyValidator())->validate(
            $this->facts(['turn' => ['intent' => 'aftercare']]),
            [],
            'Procure um médico e mantenha a pele hidratada.',
            ['flags' => ['is_aftercare' => true]],
        );

        $this->assertFalse($result['ok']);
        $this->assertSame('aftercare_without_handoff', $result['violations'][0]['rule']);
        $this->assertSame('canonical_aftercare', $result['resolution']);
        $this->assertStringContainsString('Adriano', $result['final_reply']);
    }

    public function test_aftercare_with_a_handoff_is_accepted(): void
    {
        $result = (new ReplyValidator())->validate(
            $this->facts(['turn' => ['intent' => 'aftercare']]),
            [['type' => 'handoff']],
            'Sinto muito. Vou passar seu caso para o Adriano agora.',
            ['flags' => ['is_aftercare' => true]],
        );

        $this->assertTrue($result['ok']);
    }

    public function test_a_cover_up_hint_without_the_project_type_still_blocks_discovery(): void
    {
        $result = (new ReplyValidator())->validate(
            $this->facts(),
            [['type' => 'question', 'field' => 'style']],
            'Qual estilo você quer para essa cobertura?',
            ['flags' => ['mentions_coverup' => true]],
        );

        $this->assertFalse($result['ok']);
        $this->assertContains('coverup_discovery', array_column($result['violations'], 'rule'));
        $this->assertSame('canonical_cover_up', $result['resolution']);
    }

    public function test_a_cover_up_hint_blocks_a_price(): void
    {
        $result = (new ReplyValidator())->validate(
            $this->facts(),
            [['type' => 'price', 'cents' => 60000]],
            'Fica R$ 600,00.',
            ['flags' => ['mentions_coverup' => true]],
        );

        $this->assertFalse($result['ok']);
        $this->assertContains('coverup_never_quotes', array_column($result['violations'], 'rule'));
        $this->assertSame('canonical_cover_up', $result['resolution']);
    }

    public function test_confirming_a_booking_before_the_deposit_is_blocked(): void
    {
        $result = (new ReplyValidator())->validate(
            $this->facts([
                'booking' => [
                    'active' => true,
                    'start_at' => '2026-11-14T13:00:00+00:00',
                    'created_this_turn' => true,
                    'deposit_status' => 'aguardando_confirmacao',
                ],
            ]),
            [['type' => 'booking_confirmed']],
            'Fechado! Seu horário está confirmado para sábado às 10h.',
        );

        $this->assertFalse($result['ok']);
        $this->assertContains('booking_confirmed_before_deposit', array_column($result['violations'], 'rule'));
        $this->assertSame('canonical_booking_pending_deposit', $result['resolution']);
        $this->assertStringContainsString('pré-reservado', $result['final_reply']);
        $this->assertStringContainsString('PIX', $result['final_reply']);
    }

    public function test_confirming_a_booking_after_the_deposit_is_accepted(): void
    {
        $result = (new ReplyValidator())->validate(
            $this->facts([
                'booking' => [
                    'active' => true,
                    'start_at' => '2026-11-14T13:00:00+00:00',
                    'created_this_turn' => true,
                    'deposit_status' => 'confirmado',
                ],
            ]),
            [['type' => 'booking_confirmed']],
            'Sinal recebido! Seu horário está confirmado para sábado às 10h.',
        );

        $this->assertTrue($result['ok']);
    }

    public function test_a_cover_up_reply_without_handoff_is_replaced(): void
    {
        $result = (new ReplyValidator())->validate(
            $this->facts(['project' => ['tattoo_type' => 'cover_up']]),
            [['type' => 'question', 'field' => 'photo']],
            'Me manda uma foto da tatuagem atual?',
            ['flags' => []],
        );

        $this->assertFalse($result['ok']);
        $this->assertContains('coverup_without_handoff', array_column($result['violations'], 'rule'));
        $this->assertSame('canonical_cover_up', $result['resolution']);
        $this->assertStringContainsString('Vou passar', $result['final_reply']);
    }

    public function test_re_offering_cover_up_after_the_lead_refused_is_blocked(): void
    {
        $result = (new ReplyValidator())->validate(
            $this->facts(['project' => ['tattoo_type' => null, 'placement' => 'braco']]),
            [['type' => 'question', 'field' => 'type']],
            'Você quer cobrir essa tatuagem ou fazer uma nova do lado dela?',
            ['flags' => ['coverup_refused' => true]],
        );

        $this->assertFalse($result['ok']);
        $this->assertContains('coverup_reasked_after_refusal', array_column($result['violations'], 'rule'));
        $this->assertSame('canonical_coverup_refused', $result['resolution']);
        $this->assertStringContainsString('tatuagem nova', $result['final_reply']);
    }

    public function test_asking_a_known_size_is_blocked_even_without_a_claim(): void
    {
        $result = (new ReplyValidator())->validate(
            $this->facts(['project' => ['body_zone' => 'pequeno']]),
            [],
            'Nesse local, você quer uma tattoo pequena, uma área média ou o local inteiro?',
            ['flags' => []],
        );

        $this->assertFalse($result['ok']);
        $this->assertContains('question_known_field', array_column($result['violations'], 'rule'));
    }

    public function test_re_asking_the_current_tattoo_photo_is_blocked(): void
    {
        $result = (new ReplyValidator())->validate(
            $this->facts(['project' => ['tattoo_type' => 'cover_up']]),
            [['type' => 'question', 'field' => 'photo']],
            'Me manda uma foto da tatuagem atual do braço?',
            ['flags' => ['lead_photos' => true]],
        );

        $this->assertFalse($result['ok']);
        $this->assertContains('photo_reasked', array_column($result['violations'], 'rule'));
        $this->assertSame('canonical_cover_up', $result['resolution']);
        $this->assertStringNotContainsString('foto da tatuagem atual', $result['final_reply']);
    }

    public function test_a_question_while_the_lead_is_driving_is_replaced(): void
    {
        $result = (new ReplyValidator())->validate(
            $this->facts(['project' => ['style' => null]]),
            [['type' => 'question', 'field' => 'style']],
            'Dá uma olhada no portfólio! Qual estilo você curte?',
            ['flags' => ['lead_busy' => true]],
        );

        $this->assertFalse($result['ok']);
        $this->assertContains('question_while_busy', array_column($result['violations'], 'rule'));
        $this->assertSame('canonical_busy', $result['resolution']);
        $this->assertStringContainsString('sem pressa', $result['final_reply']);
    }

    public function test_ignoring_a_cover_up_refusal_is_blocked(): void
    {
        $result = (new ReplyValidator())->validate(
            $this->facts(['project' => ['tattoo_type' => null, 'placement' => 'braco']]),
            [['type' => 'handoff']],
            'Como fica perto da que você já tem, o Adriano precisa ver esse espaço pessoalmente.',
            ['flags' => ['coverup_refused' => true]],
        );

        $this->assertFalse($result['ok']);
        $this->assertContains('coverup_refusal_ignored', array_column($result['violations'], 'rule'));
        $this->assertSame('canonical_coverup_refused', $result['resolution']);
        $this->assertStringContainsString('não dá certo', $result['final_reply']);
    }

    public function test_a_cover_or_removal_question_answers_both_and_hands_off(): void
    {
        $result = (new ReplyValidator())->validate(
            $this->facts(['project' => ['tattoo_type' => 'cover_up']]),
            [['type' => 'question', 'field' => 'photo']],
            'Me manda uma foto?',
            ['flags' => ['mentions_removal' => true]],
        );

        $this->assertFalse($result['ok']);
        $this->assertSame('canonical_cover_up', $result['resolution']);
        $this->assertStringContainsString('laser', $result['final_reply']);
        $this->assertStringContainsString('várias sessões', $result['final_reply']);
        $this->assertStringContainsString('Vou passar', $result['final_reply']);
    }

    public function test_a_time_outside_the_studio_hours_is_blocked(): void
    {
        $result = (new ReplyValidator())->validate(
            $this->facts([
                'studio' => [
                    'city' => 'Poços de Caldas',
                    'timezone' => 'America/Sao_Paulo',
                    'working_hours' => [
                        'seg' => ['09:00-12:00', '14:00-18:00'],
                        'sab' => ['09:00-13:00'],
                    ],
                ],
            ]),
            [],
            'Pode vir às 18h20 que eu te espero.',
        );

        $this->assertFalse($result['ok']);
        $this->assertSame('time_outside_hours', $result['violations'][0]['rule']);
        $this->assertSame('canonical_time', $result['resolution']);
        $this->assertStringContainsString('fora do funcionamento', $result['final_reply']);
    }

    public function test_an_unknown_violation_defers_instead_of_keeping_unsafe_text(): void
    {
        $result = (new ReplyValidator())->validate(
            $this->facts(),
            [['type' => 'location', 'city' => 'Belo Horizonte']],
            'Estamos em Belo Horizonte.',
        );

        $this->assertFalse($result['ok']);
        $this->assertSame('canonical_defer', $result['resolution']);
        $this->assertStringContainsString('confirmar', $result['final_reply']);
        $this->assertTrue($result['repairable']);
    }
}
