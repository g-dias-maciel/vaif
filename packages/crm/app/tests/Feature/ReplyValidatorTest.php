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
}
