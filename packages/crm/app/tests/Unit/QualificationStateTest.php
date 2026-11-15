<?php

namespace Tests\Unit;

use App\Services\Agent\QualificationState;
use Tests\TestCase;

class QualificationStateTest extends TestCase
{
    public function test_required_fields_block_the_price_but_optional_fields_do_not(): void
    {
        $state = new QualificationState([
            'tattoo_type' => 'new',
            'placement' => 'braco',
            'body_zone' => 'pequeno',
            'style' => 'fine_line',
            'first_tattoo' => null,
            'meaning' => null,
        ]);

        $this->assertNull($state->requiredNextField());
        $this->assertSame('first_tattoo', $state->nextField());
    }

    public function test_it_returns_the_first_required_field_in_order(): void
    {
        $state = new QualificationState([
            'tattoo_type' => 'new',
            'placement' => null,
            'body_zone' => null,
            'style' => 'fine_line',
        ]);

        $this->assertSame('placement', $state->requiredNextField());
        $this->assertSame('placement', $state->nextField());
    }

    public function test_a_confident_assumption_becomes_a_closed_confirmation(): void
    {
        $state = new QualificationState(
            ['tattoo_type' => null],
            ['tattoo_type' => ['value' => 'nova', 'confidence' => 0.8]],
        );

        $this->assertTrue($state->isConfirmation('tattoo_type'));
        $this->assertSame('Essa tatuagem seria uma tatuagem nova, certo?', $state->questionFor('tattoo_type'));
    }

    public function test_cover_up_leaves_the_normal_qualification_path(): void
    {
        $state = new QualificationState(
            ['tattoo_type' => 'cover_up'],
        );

        $this->assertNull($state->requiredNextField());
        $this->assertNull($state->nextField());
    }
}
