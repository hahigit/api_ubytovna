<?php

declare(strict_types=1);

namespace Planovac;

final class Dates
{
    public static function isValid(mixed $value): bool
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        return $d !== false && $d->format('Y-m-d') === $value;
    }

    public static function addDays(string $date, int $days): string
    {
        return (new \DateTimeImmutable($date, new \DateTimeZone('UTC')))->modify(sprintf('%+d days', $days))->format('Y-m-d');
    }

    public static function nights(string $from, string $to): int
    {
        $a = new \DateTimeImmutable($from, new \DateTimeZone('UTC'));
        $b = new \DateTimeImmutable($to, new \DateTimeZone('UTC'));
        return (int) $a->diff($b)->format('%r%a');
    }
}
