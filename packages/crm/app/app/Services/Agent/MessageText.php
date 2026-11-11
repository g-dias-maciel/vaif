<?php

namespace App\Services\Agent;

/**
 * WhatsApp payloads carry media as bracketed placeholders. A reply that is
 * only `[MÍDIA ENVIADA]` (or only emoji) cannot be compared with a text draft.
 */
class MessageText
{
    private const MARKERS = [
        '[MÍDIA ENVIADA]',
        '[MÍDIA RECEBIDA]',
        '[FOTO RECEBIDA]',
        '[FOTO ENVIADA]',
        '[VÍDEO RECEBIDO]',
        '[VÍDEO ENVIADO]',
        '[ÁUDIO RECEBIDO]',
        '[ÁUDIO ENVIADO]',
        '[DOCUMENTO RECEBIDO]',
        '[DOCUMENTO ENVIADO]',
        '[STICKER RECEBIDO]',
        '[STICKER ENVIADO]',
    ];

    public static function isMediaOnly(?string $text): bool
    {
        $clean = str_replace(self::MARKERS, ' ', (string) $text);
        $clean = preg_replace('/[\p{So}\p{Sk}\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}]/u', ' ', $clean) ?? $clean;

        return preg_match('/[\p{L}\d]/u', $clean) !== 1;
    }

    public static function hasMediaMarker(?string $text): bool
    {
        foreach (self::MARKERS as $marker) {
            if (str_contains((string) $text, $marker)) {
                return true;
            }
        }

        return false;
    }
}
