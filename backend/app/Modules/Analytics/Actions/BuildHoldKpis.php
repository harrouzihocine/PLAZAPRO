<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Actions;

use App\Modules\Analytics\Support\KpiFilters;
use App\Modules\Analytics\Support\KpiMath;
use App\Modules\Inventory\Enums\HoldStatus;
use App\Modules\Inventory\Models\Reservation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reservation hold-engine KPIs (catalog §3). "Active" is point-in-time; the
 * conversion / duration / outcome figures follow the cohort of holds first
 * placed inside the window (held_at), so a hold is judged by what became of it.
 * Dimension filters narrow via the held unit.
 */
class BuildHoldKpis
{
    /**
     * @return array<string, mixed>
     */
    public function handle(KpiFilters $f): array
    {
        $cohort = $this->cohort($f); // hold_status => count, for holds placed in the window
        $new = (int) $cohort->sum();
        $converted = $cohort->get(HoldStatus::Converted->value, 0);
        $expired = $cohort->get(HoldStatus::Expired->value, 0);
        $released = $cohort->get(HoldStatus::Released->value, 0);
        $stillActive = $cohort->get(HoldStatus::Active->value, 0);

        return [
            'active_holds' => $this->activeHolds($f),
            'new_holds' => $new,
            'converted' => $converted,
            'conversion_rate' => KpiMath::pct($converted, $new),
            'expired' => $expired,
            'released' => $released,
            'avg_duration_days' => $this->avgDurationDays($f),
            // Advanced: of the holds that resolved either way, how many converted.
            'reliability_index' => KpiMath::pct($converted, $converted + $expired),
            'outcomes' => [
                ['status' => 'active', 'count' => $stillActive],
                ['status' => 'converted', 'count' => $converted],
                ['status' => 'expired', 'count' => $expired],
                ['status' => 'released', 'count' => $released],
            ],
            'by_location' => $this->byLocation($f),
        ];
    }

    /**
     * Cohort of holds placed within the window (held_at) counted by outcome.
     *
     * @return Collection<string, int> hold_status => count (new_holds = total)
     */
    private function cohort(KpiFilters $f): Collection
    {
        return $this->scoped(Reservation::query()->active(), $f)
            ->whereBetween('reservations.held_at', [$f->start, $f->end])
            ->selectRaw('reservations.hold_status, COUNT(*) as c')
            ->groupBy('reservations.hold_status')
            ->pluck('c', 'hold_status')
            ->map(fn ($c) => (int) $c);
    }

    private function activeHolds(KpiFilters $f): int
    {
        return $this->scoped(Reservation::query()->active(), $f)
            ->where('reservations.hold_status', HoldStatus::Active->value)
            ->count();
    }

    /** Average days a resolved hold lived (held_at → resolution ≈ updated_at). */
    private function avgDurationDays(KpiFilters $f): ?int
    {
        $avg = $this->scoped(Reservation::query()->active(), $f)
            ->whereIn('reservations.hold_status', [
                HoldStatus::Converted->value, HoldStatus::Expired->value, HoldStatus::Released->value,
            ])
            ->whereBetween('reservations.held_at', [$f->start, $f->end])
            ->avg(DB::raw('DATEDIFF(reservations.updated_at, reservations.held_at)'));

        return $avg === null ? null : (int) round((float) $avg);
    }

    /**
     * Active holds per development.
     *
     * @return list<array{location: string, count: int}>
     */
    private function byLocation(KpiFilters $f): array
    {
        return Reservation::query()->active()
            ->where('reservations.hold_status', HoldStatus::Active->value)
            ->join('units', 'units.id', '=', 'reservations.unit_id')
            ->join('locations', 'locations.id', '=', 'units.location_id')
            ->when($f->locationId, fn ($q) => $q->where('units.location_id', $f->locationId))
            ->when($f->unitType, fn ($q) => $q->where('units.room_number_id', $f->unitType))
            ->selectRaw('locations.name as name, COUNT(*) as c')
            ->groupBy('name')
            ->orderByDesc('c')
            ->get()
            ->map(fn ($r) => ['location' => (string) $r->name, 'count' => (int) $r->c])
            ->all();
    }

    /** Apply the development + room-type dimensions via the held unit. */
    private function scoped(Builder $query, KpiFilters $f): Builder
    {
        if (! $f->hasLocation() && ! $f->hasUnitType()) {
            return $query;
        }

        return $query
            ->join('units', 'units.id', '=', 'reservations.unit_id')
            ->when($f->locationId, fn ($q) => $q->where('units.location_id', $f->locationId))
            ->when($f->unitType, fn ($q) => $q->where('units.room_number_id', $f->unitType));
    }
}
