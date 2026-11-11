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
