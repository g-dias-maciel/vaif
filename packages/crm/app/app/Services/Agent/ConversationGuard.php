<?php

namespace App\Services\Agent;

use Illuminate\Support\Str;

/**
 * Stops the agent from engaging with personal/abusive chats and from sending a
 * second "wrong number" reply after the first one.
 */
class ConversationGuard
{
    /**
     * @param  array<int, array{role: string, content: string}>  $history
     */
    public static function skipReason(string $intent, array $history): ?string
    {
        if ($intent === 'personal') {
            return 'personal';
        }

        if ($intent === 'spam' && self::spamAlreadyClosed($history)) {
            return 'spam_after_close';
        }

        if (self::handoffAlreadyDone($history)) {
            return 'handoff_done';
        }

        return null;
    }

    /**
     * The artist was already handed the conversation. No further agent turn
     * should be generated, including in draft/calibration mode.
     *
     * @param  array<int, array{role: string, content: string}>  $history
     */
    public static function handoffAlreadyDone(array $history): bool
    {
        foreach ($history as $message) {
            if (($message['role'] ?? 'user') !== 'assistant') {
                continue;
            }

            $content = Str::ascii(mb_strtolower((string) ($message['content'] ?? '')));

            foreach ([
                'vou passar para o adriano',
                'vou te passar para',
                'vou passar seu caso',
                'vou encaminhar',
                'encaminhei',
                'passo para o adriano',
                'passar para o artista',
                'ele vai entrar em contato',
                'vou confirmar com o artista',
            ] as $phrase) {
                if (str_contains($content, $phrase)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $history
     */
    public static function spamAlreadyClosed(array $history): bool
    {
        foreach ($history as $message) {
            if (($message['role'] ?? 'user') !== 'assistant') {
                continue;
            }

            $content = Str::ascii(mb_strtolower((string) ($message['content'] ?? '')));

            if (preg_match('/numero errado|so tatuagem|estudio de tatuagem|studio de tatuagem|nao e do estudio/u', $content) === 1) {
                return true;
            }
        }

        return false;
    }
}
