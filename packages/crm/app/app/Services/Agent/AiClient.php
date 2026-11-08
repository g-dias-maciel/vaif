<?php

namespace App\Services\Agent;

interface AiClient
{
    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     */
    public function chat(array $messages, string $model): string;
}
