<?php

namespace Tests\Unit;

use App\Services\Agent\MessageText;
use PHPUnit\Framework\TestCase;

class MessageTextTest extends TestCase
{
    public function test_media_placeholders_are_media_only(): void
    {
        $this->assertTrue(MessageText::isMediaOnly('[MÍDIA ENVIADA]'));
        $this->assertTrue(MessageText::isMediaOnly("[MÍDIA ENVIADA]\n[FOTO RECEBIDA]\n  "));
        $this->assertTrue(MessageText::isMediaOnly('🙏🏻'));
        $this->assertTrue(MessageText::isMediaOnly(''));
        $this->assertTrue(MessageText::isMediaOnly(null));
    }

    public function test_a_real_reply_is_not_media_only(): void
    {
        $this->assertFalse(MessageText::isMediaOnly('Boa noite'));
        $this->assertFalse(MessageText::isMediaOnly('[MÍDIA ENVIADA] Essas são as fotos'));
        $this->assertFalse(MessageText::isMediaOnly('R$ 250'));
    }
}
