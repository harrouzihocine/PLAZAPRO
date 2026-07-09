<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Actions;

use App\Modules\Analytics\Models\KpiSnapshot;
use Carbon\CarbonImmutable;

/**
 * Reads the materialized kpi_snapshots into time-series for the trend charts.
 * Flow metrics (sales_value, collected, units_sold) form a curve; balance
 * metrics (receivables_outstanding, inventory_value…) form a burn-down. A gap
 * day simply has no point — the frontend line skips it.
 */
class BuildKpiTrends
{
    /** The metrics the command center charts by default. */
    private const DEFAULT_METRICS = [
        'sales_value', 'collected', 'receivables_outstanding', 'available_units', 'inventory_value',
    ];

    /**
     * @param  list<string>  $metrics
     * @return array<string, list<array{date: string, value: string}>>
     */
    public function handle(?array $metrics = null, int $days = 90, ?int $locationId = null): array
    {
        $metrics = $metrics ?: self::DEFAULT_METRICS;
        $from = CarbonImmutable::now()->subDays($days)->toDateString();
        $dimension = $locationId !== null ? 'location:'.$locationId : null;

        $rows = KpiSnapshot::query()
            ->whereIn('metric', $metrics)
            ->where('dimension', $dimension)
            ->where('snapshot_date', '>=', $from)
            ->orderBy('snapshot_date')
            ->get(['metric', 'snapshot_date', 'value']);

        $series = array_fill_keys($metrics, []);
        foreach ($rows as $row) {
            $series[$row->metric][] = [
                'date' => $row->snapshot_date->toDateString(),
                'value' => (string) $row->value,
            ];
        }

        return $series;
    }
}
