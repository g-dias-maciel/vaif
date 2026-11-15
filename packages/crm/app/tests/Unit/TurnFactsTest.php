<?php

namespace Tests\Unit;

use App\Services\Agent\TurnFacts;
use PHPUnit\Framework\TestCase;

class TurnFactsTest extends TestCase
{
    public function test_it_merges_fields_answered_in_the_current_turn(): void
    {
        $facts = TurnFacts::mergeEntities(
            ['project' => ['meaning' => null, 'first_tattoo' => null, 'tattoo_type' => null]],
            ['meaning' => 'flor de lótus para minha mãe', 'first_tattoo' => false, 'tattoo_type' => 'nova'],
        );

        $this->assertSame('flor de lótus para minha mãe', $facts['project']['meaning']);
        $this->assertFalse($facts['project']['first_tattoo']);
        $this->assertSame('new', $facts['project']['tattoo_type']);
    }

    public function test_it_maps_cover_up_values_to_the_project_vocabulary(): void
    {
        $facts = TurnFacts::mergeEntities([], ['tattoo_type' => 'cobertura']);

        $this->assertSame('cover_up', $facts['project']['tattoo_type']);
    }
}
