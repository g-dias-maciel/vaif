<?php

namespace Tests\Feature;

use App\Services\Agent\Eval\ScriptedAiClient;
use App\Services\Agent\PreflightClassifier;
use Tests\TestCase;

class PreflightClassifierTest extends TestCase
{
    private function classifier(array $preflight): PreflightClassifier
    {
        return new PreflightClassifier(new ScriptedAiClient(['preflight' => $preflight]), 'scripted');
    }

    public function test_it_parses_context_assumptions(): void
    {
        $result = $this->classifier([
            'intent' => 'qualify',
            'entities' => ['placement' => 'mao'],
            'assumptions' => [
                'tattoo_type' => ['value' => 'nova', 'confidence' => 0.86, 'reason' => 'pediu na mão'],
                'first_tattoo' => ['value' => 'true', 'confidence' => 0.7, 'reason' => 'nunca falou de tatuagem'],
                'body_zone' => null,
                'meaning' => ['value' => '   ', 'confidence' => 0.9],
            ],
            'flags' => ['mentions_promotion' => true],
            'confidence' => 0.9,
        ])->classify('contexto', 'quero fazer na mão');

        $this->assertSame('nova', $result['assumptions']['tattoo_type']['value']);
        $this->assertSame(0.86, $result['assumptions']['tattoo_type']['confidence']);
        $this->assertTrue($result['assumptions']['first_tattoo']['value']);
        $this->assertArrayNotHasKey('body_zone', $result['assumptions']);
        $this->assertArrayNotHasKey('meaning', $result['assumptions']);
        $this->assertTrue($result['flags']['mentions_promotion']);
    }

    public function test_an_inferred_entity_is_demoted_to_an_assumption(): void
    {
        $result = $this->classifier([
            'intent' => 'qualify',
            'entities' => ['placement' => 'panturrilha', 'tattoo_type' => 'nova', 'first_tattoo' => false],
            'assumptions' => [
                'tattoo_type' => ['value' => 'nova', 'confidence' => 0.9, 'reason' => 'não quer cobrir as do braço'],
            ],
            'flags' => [],
            'confidence' => 0.9,
        ])->classify(
            'contexto',
            'Quero fazer na panturrilha, porque no braço eu já tenho outra e não quero cobrir.',
        );

        $this->assertSame('panturrilha', $result['entities']['placement']);
        $this->assertArrayNotHasKey('tattoo_type', $result['entities']);
        $this->assertSame('nova', $result['assumptions']['tattoo_type']['value']);
        $this->assertSame(0.9, $result['assumptions']['tattoo_type']['confidence']);
        $this->assertFalse($result['entities']['first_tattoo']);
    }

    public function test_an_explicitly_stated_entity_is_kept(): void
    {
        $result = $this->classifier([
            'intent' => 'qualify',
            'entities' => ['placement' => 'perna', 'tattoo_type' => 'nova'],
            'assumptions' => [],
            'flags' => [],
            'confidence' => 0.9,
        ])->classify('contexto', 'Quero fazer uma tatuagem nova na perna.');

        $this->assertSame('perna', $result['entities']['placement']);
        $this->assertSame('nova', $result['entities']['tattoo_type']);
        $this->assertArrayNotHasKey('tattoo_type', $result['assumptions']);
    }

    public function test_a_wrong_placement_is_demoted_to_an_assumption(): void
    {
        $result = $this->classifier([
            'intent' => 'ask_price',
            'entities' => ['placement' => 'coxa', 'body_zone' => 'medio'],
            'assumptions' => [],
            'flags' => [],
            'confidence' => 0.8,
        ])->classify('contexto', 'Pode me passar o orçamento para fazer na parte da frente da perna, uns 13 cm?');

        $this->assertArrayNotHasKey('placement', $result['entities']);
        $this->assertSame('coxa', $result['assumptions']['placement']['value']);
        $this->assertSame(0.7, $result['assumptions']['placement']['confidence']);
        $this->assertSame('medio', $result['assumptions']['body_zone']['value']);
    }

    public function test_negated_cover_up_is_not_explicit(): void
    {
        $result = $this->classifier([
            'intent' => 'qualify',
            'entities' => ['tattoo_type' => 'cobertura'],
            'assumptions' => [],
            'flags' => [],
            'confidence' => 0.8,
        ])->classify('contexto', 'Eu não quero cobrir a do braço, quero fazer na perna.');

        $this->assertArrayNotHasKey('tattoo_type', $result['entities']);
        $this->assertSame('cobertura', $result['assumptions']['tattoo_type']['value']);
    }

    public function test_the_assistant_question_is_not_evidence_for_the_lead(): void
    {
        $result = $this->classifier([
            'intent' => 'qualify',
            'entities' => ['tattoo_type' => 'nova'],
            'assumptions' => [],
            'flags' => [],
            'confidence' => 0.8,
        ])->classify(
            'contexto',
            'Quero fazer na perna.',
            [['role' => 'assistant', 'content' => 'Essa tattoo é nova, uma cobertura ou uma reforma?']],
        );

        $this->assertArrayNotHasKey('tattoo_type', $result['entities']);
        $this->assertSame('nova', $result['assumptions']['tattoo_type']['value']);
    }

    public function test_a_short_confirmation_accepts_the_previous_confirmation_question(): void
    {
        $result = $this->classifier([
            'intent' => 'qualify',
            'entities' => ['tattoo_type' => 'nova'],
            'assumptions' => [],
            'flags' => [],
            'confidence' => 0.9,
        ])->classify(
            'contexto',
            'Sim, isso',
            [['role' => 'assistant', 'content' => 'Essa tatuagem seria uma tatuagem nova, certo?']],
        );

        $this->assertSame('nova', $result['entities']['tattoo_type']);
        $this->assertArrayNotHasKey('tattoo_type', $result['assumptions']);
    }

    public function test_a_name_not_written_by_the_lead_is_dropped(): void
    {
        $result = $this->classifier([
            'intent' => 'qualify',
            'entities' => ['name' => 'Mariana'],
            'assumptions' => [],
            'flags' => [],
            'confidence' => 0.9,
        ])->classify('contexto', 'Quero fazer na perna.');

        $this->assertArrayNotHasKey('name', $result['entities']);
    }

    public function test_it_detects_a_cover_up_hint_deterministically(): void
    {
        $result = $this->classifier([
            'intent' => 'other',
            'entities' => [],
            'assumptions' => [],
            'flags' => ['mentions_coverup' => false],
            'confidence' => 0.8,
        ])->classify('contexto', 'Quero cobrir essa tatuagem antiga do braço.');

        $this->assertTrue($result['flags']['mentions_coverup']);
    }

    public function test_it_overrides_a_false_positive_cover_up_hint(): void
    {
        $result = $this->classifier([
            'intent' => 'qualify',
            'entities' => [],
            'assumptions' => [],
            'flags' => ['mentions_coverup' => true],
            'confidence' => 0.8,
        ])->classify('contexto', 'Eu não quero cobrir a do braço, quero fazer na perna.');

        $this->assertFalse($result['flags']['mentions_coverup']);
    }

    public function test_it_flags_a_booking_confirmed_in_the_chat(): void
    {
        $result = $this->classifier([
            'intent' => 'close',
            'entities' => [],
            'assumptions' => [],
            'flags' => [],
            'confidence' => 0.9,
        ])->classify(
            'contexto',
            'Passa o Pix pfvr',
            [
                ['role' => 'user', 'content' => 'Pode ser kkkkk'],
                ['role' => 'assistant', 'content' => 'Fica agendado para sexta às 10h.'],
            ],
        );

        $this->assertTrue($result['flags']['booking_in_chat']);
    }

    public function test_it_flags_photos_busy_lead_and_cover_up_refusal(): void
    {
        $result = $this->classifier([
            'intent' => 'qualify',
            'entities' => [],
            'assumptions' => [],
            'flags' => [],
            'confidence' => 0.8,
        ])->classify(
            'contexto',
            'Vc consegue me mandar mais modelos?',
            [
                ['role' => 'user', 'content' => 'Tô na correria, tô dirigindo agora.'],
                ['role' => 'user', 'content' => '[FOTO RECEBIDA]'],
                ['role' => 'user', 'content' => 'Não quero cobrir a do outro braço.'],
            ],
        );

        $this->assertTrue($result['flags']['lead_photos']);
        $this->assertTrue($result['flags']['lead_busy']);
        $this->assertTrue($result['flags']['coverup_refused']);
    }

    public function test_it_drops_low_confidence_and_unknown_assumptions(): void
    {
        $result = $this->classifier([
            'intent' => 'qualify',
            'entities' => [],
            'assumptions' => [
                'tattoo_type' => ['value' => 'nova', 'confidence' => 0.2],
                'style' => ['value' => 'realismo', 'confidence' => 0.8],
                'price' => ['value' => '600', 'confidence' => 1],
                'first_tattoo' => ['value' => 'talvez', 'confidence' => 0.9],
            ],
            'flags' => [],
            'confidence' => 0.8,
        ])->classify('contexto', 'quero uma tattoo');

        $this->assertArrayNotHasKey('tattoo_type', $result['assumptions']);
        $this->assertArrayNotHasKey('price', $result['assumptions']);
        $this->assertArrayNotHasKey('first_tattoo', $result['assumptions']);
        $this->assertSame('realismo', $result['assumptions']['style']['value']);
    }
}
