<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Support;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Turns the shared KPI query params into a resolved KpiFilters window. Dates are
 * interpreted in the app timezone (Africa/Algiers is the app default, so
 * now()/parse() already land there). The previous window is the preceding
 * CALENDAR unit for the presets (last month, last quarter…) and an equal-length
 * window immediately before a custom range — the natural "vs previous" baseline.
 *
 * Params: period (today|week|month|quarter|year|custom, default month),
 * from,to (Y-m-d, used by custom or to override any preset), location_id,
 * unit_type (room_numbers id), agent_id.
 */
class ResolveKpiFilters
{
    public function handle(Request $request): KpiFilters
    {
        $period = $request->string('period')->toString() ?: 'month';
        $now = CarbonImmutable::now();

        [$start, $end, $prevStart, $prevEnd] = $this->window($period, $now, $request);

        return new KpiFilters(
            period: $period,
            start: $start,
            end: $end,
            prevStart: $prevStart,
            prevEnd: $prevEnd,
            locationId: $request->filled('location_id') ? $request->integer('location_id') : null,
            unitType: $request->filled('unit_type') ? $request->integer('unit_type') : null,
            agentId: $request->filled('agent_id') ? $request->integer('agent_id') : null,
        );
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: CarbonImmutable, 3: CarbonImmutable}
     */
    private function window(string $period, CarbonImmutable $now, Request $request): array
    {
        if ($period === 'custom' || $request->filled('from') || $request->filled('to')) {
            $start = $request->filled('from')
                ? CarbonImmutable::parse($request->string('from')->toString())->startOfDay()
                : $now->startOfMonth();
            $end = $request->filled('to')
                ? CarbonImmutable::parse($request->string('to')->toString())->endOfDay()
                : $now->endOfDay();
            // Equal-length window immediately before the custom range.
            $len = $start->startOfDay()->diffInDays($end->startOfDay()) + 1;
            $prevEnd = $start->subDay()->endOfDay();
            $prevStart = $prevEnd->subDays($len - 1)->startOfDay();

            return [$start, $end, $prevStart, $prevEnd];
        }

        return match ($period) {
            'today' => [
                $now->startOfDay(), $now->endOfDay(),
                $now->subDay()->startOfDay(), $now->subDay()->endOfDay(),
            ],
            'week' => [
                $now->startOfWeek(), $now->endOfWeek(),
                $now->subWeek()->startOfWeek(), $now->subWeek()->endOfWeek(),
            ],
            'quarter' => [
                $now->startOfQuarter(), $now->endOfQuarter(),
                $now->subQuarter()->startOfQuarter(), $now->subQuarter()->endOfQuarter(),
            ],
            'year' => [
                $now->startOfYear(), $now->endOfYear(),
                $now->subYear()->startOfYear(), $now->subYear()->endOfYear(),
            ],
            default => [ // month
                $now->startOfMonth(), $now->endOfMonth(),
                $now->subMonth()->startOfMonth(), $now->subMonth()->endOfMonth(),
            ],
        };
    }
}
