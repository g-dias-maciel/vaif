<?php

namespace Tests\Unit;

use App\Models\ChatMessage;
use PHPUnit\Framework\TestCase;

class ChatMessageTest extends TestCase
{
    private function message(array $message): ChatMessage
    {
        return (new ChatMessage)->forceFill(['message' => $message]);
    }

    public function test_tool_envelopes_and_empty_ai_rows_are_not_displayable(): void
    {
        $tool = $this->message([
            'type' => 'tool',
            'content' => '[{"id":"booking-1"}]',
        ]);
        $emptyAi = $this->message([
            'type' => 'ai',
            'content' => '[]',
        ]);
        $reply = $this->message([
            'type' => 'ai',
            'content' => 'Posso te atender amanhã às 9 horas.',
        ]);
        $human = $this->message([
            'type' => 'human',
            'content' => 'Oi',
        ]);

        $this->assertFalse($tool->is_displayable);
        $this->assertFalse($emptyAi->is_displayable);
        $this->assertTrue($reply->is_displayable);
        $this->assertTrue($human->is_displayable);
    }
}
