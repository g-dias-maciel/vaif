<?php

namespace Tests\Unit;

use App\Models\ChatMessage;
use PHPUnit\Framework\TestCase;

class ChatMessageTest extends TestCase
{
    private function message(string $type, array $extra = []): ChatMessage
    {
        $m = new ChatMessage();
        $m->message = array_merge(['type' => $type, 'content' => 'texto'], $extra);

        return $m;
    }

    public function test_human_messages_are_labelled_as_the_client(): void
    {
        $m = $this->message('human');

        $this->assertSame('inbound', $m->direction);
        $this->assertSame('Cliente', $m->sender_label);
        $this->assertFalse($m->is_artist);
    }

    public function test_ai_messages_are_labelled_as_beatriz_by_default(): void
    {
        $m = $this->message('ai');

        $this->assertSame('outbound', $m->direction);
        $this->assertSame('Beatriz', $m->sender_label);
        $this->assertFalse($m->is_artist);
    }

    public function test_artist_messages_are_labelled_as_the_artist(): void
    {
        $m = $this->message('ai', ['additional_kwargs' => ['author' => 'artist']]);

        $this->assertSame('outbound', $m->direction);
        $this->assertSame('Artista', $m->sender_label);
        $this->assertTrue($m->is_artist);
    }
}
