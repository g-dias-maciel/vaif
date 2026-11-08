<?php

namespace App\Services\Agent;

class Json
{
    public static function parse(string $content): array
    {
        $content = trim($content);
        $content = preg_replace('/^```(?:json)?\s*/i', '', $content) ?? $content;
        $content = preg_replace('/\s*```$/', '', $content) ?? $content;

        $decoded = json_decode($content, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        $start = strpos($content, '{');
        $end = strrpos($content, '}');

        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($content, $start, $end - $start + 1), true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    public static function string(mixed $value): string
    {
        return json_encode($value ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    public static function money(?int $cents): string
    {
        if ($cents === null) {
            return '(não informado)';
        }

        return 'R$ '.number_format($cents / 100, 2, ',', '.');
    }
}
