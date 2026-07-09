<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The resolved global filters every KPI query shares: a period window (in the
 * app timezone, Africa/Algiers) plus the immediately-preceding window for
 * Δ-vs-previous comparisons, and the three dimension narrowings — development
 * (location), unit type (room_numbers id) and agent.
 *
 * Built by ResolveKpiFilters from the request; a plain immutable data holder so
 * services read its fields and apply the dimension scopes where they make sense.
 */
final class KpiFilters
{
    public function __construct(
        public readonly string $period,        // today|week|month|quarter|year|custom
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public readonly CarbonImmutable $prevStart,
        public readonly CarbonImmutable $prevEnd,
        public readonly ?int $locationId = null,
        public readonly ?int $unitType = null, // room_numbers dynamic-list id
        public readonly ?int $agentId = null,
    ) {}

    public function hasLocation(): bool
    {
        return $this->locationId !== null;
    }

    public function hasUnitType(): bool
    {
        return $this->unitType !== null;
    }

    public function hasAgent(): bool
    {
        return $this->agentId !== null;
    }

    /**
     * Narrow a query over the `units` table (or a join that exposes those
     * columns) by the development + unit-type dimensions. The agent dimension is
     * never a unit attribute, so it is applied per service where it applies.
     */
    public function applyUnitScope(Builder $query, string $table = 'units'): Builder
    {
        return $query
            ->when($this->locationId, fn ($q) => $q->where($table.'.location_id', $this->locationId))
            ->when($this->unitType, fn ($q) => $q->where($table.'.room_number_id', $this->unitType));
    }

    /** The window length in whole days (inclusive), used for run-rates. */
    public function days(): int
    {
        return $this->start->startOfDay()->diffInDays($this->end->startOfDay()) + 1;
    }
}
