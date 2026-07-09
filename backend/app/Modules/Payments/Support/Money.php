<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

/**
 * Money arithmetic done with bcmath on decimal strings — never floats — so a full
 * schedule reconciles exactly with no drift. All amounts are decimal(12,2); the
 * rounding rule is half-up (away from zero) to 2 dp.
 */
final class Money
{
    public const SCALE = 2;

    /** Sum any number of decimal strings at 2 dp. */
    public static function sum(iterable $amounts): string
    {
        $total = '0';
        foreach ($amounts as $amount) {
            $total = bcadd($total, self::normalize((string) $amount), self::SCALE);
        }

        return self::normalize($total);
    }

    public static function add(string $a, string $b): string
    {
        return self::normalize(bcadd($a, $b, self::SCALE));
    }

    public static function sub(string $a, string $b): string
    {
        return self::normalize(bcsub($a, $b, self::SCALE));
    }

    /** a ÷ b at 2 dp — returns '0.00' when b is zero (safe average/ratio). */
    public static function div(string $a, string $b, int $scale = self::SCALE): string
    {
        if (bccomp($b, '0', self::SCALE) === 0) {
            return self::normalize('0');
        }

        return self::normalize(bcdiv($a, $b, $scale));
    }

    /** -1 if a<b, 0 if equal, 1 if a>b — compared at 2 dp. */
    public static function compare(string $a, string $b): int
    {
        return bccomp($a, $b, self::SCALE);
    }

    public static function equals(string $a, string $b): bool
    {
        return self::compare($a, $b) === 0;
    }

    public static function isZero(string $a): bool
    {
        return self::compare($a, '0') === 0;
    }

    public static function isPositive(string $a): bool
    {
        return self::compare($a, '0') > 0;
    }

    /** Round a raw value half-up (away from zero) to 2 dp. */
    public static function roundHalfUp(string $value, int $scale = self::SCALE): string
    {
        $increment = '0.'.str_repeat('0', $scale).'5'; // e.g. 0.005 at scale 2
        $rounded = bccomp($value, '0', $scale + 6) >= 0
            ? bcadd($value, $increment, $scale)
            : bcsub($value, $increment, $scale);

        return self::normalize($rounded);
    }

    /** Canonical 2-dp representation (e.g. "1000.00"), collapsing "-0.00" to "0.00". */
    public static function normalize(string $value): string
    {
        $value = bcadd($value, '0', self::SCALE);

        return $value === '-0.00' ? '0.00' : $value;
    }
}
