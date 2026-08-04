<?php

declare(strict_types=1);

function slugify(string $text): string
{
    if ($text === '') {
        return '';
    }

    $text = mb_strtolower($text);

    $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT', $text);
    if ($transliterated !== false) {
        $text = $transliterated;
    }

    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    $text = trim($text, '-');

    return $text;
}
