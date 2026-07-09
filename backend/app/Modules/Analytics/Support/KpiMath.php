<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Support;

/**
 * Tiny numeric helpers shared by the KPI services. Percentages and deltas are
 * display figures (rounded, presentation-only), so ordinary float math is fine
 * here — money AGGREGATION still happens in SQL / bcmath, never here.
 */
final class KpiMath
{
    /** A safe percentage (part ÷ whole × 100), 0 when the whole is 0. */
    public static function pct(float|int|string $part, float|int|string $whole, int $decimals = 1): float
    {
        $whole = (float) $whole;
        if ($whole == 0.0) {
            return 0.0;
        }

        return round((float) $part / $whole * 100, $decimals);
    }

    /**
     * Percent change of $current vs $previous (the Δ badge). Null when there is
     * no baseline (previous = 0) so the UI shows "new" instead of ∞%.
     */
    public static function delta(float|int|string $current, float|int|string $previous, int $decimals = 1): ?float
    {
        $previous = (float) $previous;
        if ($previous == 0.0) {
            return null;
        }

        return round(((float) $current - $previous) / $previous * 100, $decimals);
    }

    /** A safe ratio (a ÷ b), 0 when b is 0. */
    public static function ratio(float|int|string $a, float|int|string $b, int $decimals = 2): float
    {
        $b = (float) $b;
        if ($b == 0.0) {
            return 0.0;
        }

        return round((float) $a / $b, $decimals);
    }
}
