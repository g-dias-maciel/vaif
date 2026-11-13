<?php

namespace App\Services\Agent;

/**
 * Media descriptions are stored as one segment per media bubble, but older
 * descriptions can contain the same segment twice (the same WhatsApp media id
 * was described more than once) or overlap between drafts. This helper makes
 * the note idempotent before it is shown or judged.
 */
class MediaNote
{
    /**
     * @return array<int, string>
     */
    public static function segments(?string $note): array
    {
        $note = trim((string) $note);

        if ($note === '') {
            return [];
        }

        $parts = preg_split('/(?=(?:Áudio do artista:|Imagem enviada pelo artista:))/u', $note) ?: [$note];
        $seen = [];
        $out = [];

        foreach ($parts as $part) {
            $part = trim($part);

            if ($part === '') {
                continue;
            }

            $key = self::key($part);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $out[] = $part;
        }

        return $out;
    }

    public static function dedupe(?string $note): string
    {
        return implode("\n", self::segments($note));
    }

    public static function key(string $segment): string
    {
        return preg_replace('/\s+/', ' ', mb_strtolower(trim($segment))) ?? trim($segment);
    }
}
