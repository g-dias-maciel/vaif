<?php

namespace Tests\Unit;

use App\Services\Agent\ConversationGuard;
use PHPUnit\Framework\TestCase;

class ConversationGuardTest extends TestCase
{
    public function test_a_postponing_lead_is_not_requalified(): void
    {
        $this->assertSame(
            'closing',
            ConversationGuard::skipReason('other', [], 'Vou conversar com meu marido e te aviso'),
        );
    }

    public function test_a_lead_who_went_to_another_artist_is_closed(): void
    {
        $this->assertSame(
            'closing',
            ConversationGuard::skipReason('other', [], 'Consegui fazer com um amigo, obrigado'),
        );
    }

    public function test_an_acceptance_is_not_treated_as_a_close(): void
    {
        $this->assertNull(ConversationGuard::skipReason('other', [], 'Ok, pode ser'));
    }

    public function test_a_price_question_is_never_skipped_as_a_close(): void
    {
        $this->assertNull(ConversationGuard::skipReason('ask_price', [], 'Quanto fica?'));
    }
}
