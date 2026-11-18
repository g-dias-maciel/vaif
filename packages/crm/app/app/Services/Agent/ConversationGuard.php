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
    public static function skipReason(string $intent, array $history, ?string $leadText = null): ?string
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

        if ($leadText !== null && self::isClosing($leadText, $intent)) {
            return 'closing';
        }

        return null;
    }

    /**
     * A lead who is thanking, saying goodbye, postponing or already got the
     * tattoo elsewhere does not need another qualification question.
     */
    public static function isClosing(string $leadText, string $intent): bool
    {
        if (in_array($intent, ['ask_price', 'ask_availability', 'choose_slot', 'close'], true)) {
            return false;
        }

        $text = Str::ascii(mb_strtolower(trim($leadText)));

        if ($text === '' || str_contains($text, '?')) {
            return false;
        }

        foreach ([
            '/\bobrigad/',
            '/\b(valeu|tchau|ate mais|boa semana|bom trabalho)\b/',
            '/(vou|vamos) (ver|pensar|conversar|dar uma pensada)[^.]{0,60}(te aviso|avisar|retorno|falo|chamo)/',
            '/\b(te aviso|te falo depois|depois eu te falo|te chamo depois)\b/',
            '/\b(pode ficar tranquilo|sem pressa|vai no seu tempo)\b/',
            '/\bconsegui (fazer )?(com )?(outro|um amigo|um tatuador)\b/',
            '/\bnao vou (fazer )?(agora|conseguir agora)\b/',
            '/\bvou deixar (para|pra) (o ano|depois|mais pra frente|dezembro|janeiro)\b/',
        ] as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
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
