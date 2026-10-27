<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Shared time-period options for the dashboard pipeline and analytics.
 *
 * Keeping this in one place stops the two screens from drifting apart when a
 * new window (e.g. "7d") is added.
 */
class Periods
{
    public const DEFAULT = '30d';

    /**
     * Period keys → Portuguese labels.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            '30d' => 'Últimos 30 dias',
            'this_month' => 'Este mês',
            '90d' => 'Últimos 90 dias',
            'all' => 'Todos os períodos',
        ];
    }

    public static function isValid(mixed $period): bool
    {
        return is_string($period) && array_key_exists($period, self::options());
    }

    /**
     * The lower bound for a period, or null for "all time".
     */
    public static function start(string $period): ?CarbonImmutable
    {
        return match ($period) {
            'this_month' => CarbonImmutable::now()->startOfMonth(),
            '90d' => CarbonImmutable::now()->subDays(90),
            'all' => null,
            default => CarbonImmutable::now()->subDays(30),
        };
    }

    public static function label(string $period): string
    {
        return self::options()[$period] ?? self::options()[self::DEFAULT];
    }

    /**
     * Resolve a raw request value into a valid period key.
     */
    public static function fromRequest(mixed $period): string
    {
        return self::isValid($period) ? $period : self::DEFAULT;
    }
}
