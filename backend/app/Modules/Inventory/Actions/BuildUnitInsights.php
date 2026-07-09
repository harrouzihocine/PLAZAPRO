<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Clients\Models\ClientProject;
use App\Modules\Clients\Models\DealItem;
use App\Modules\Clients\Models\ShortlistItem;
use App\Modules\Inventory\Models\Reservation;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Payments\Models\PaymentSchedule;
use App\Modules\Payments\Models\Versement;
use Illuminate\Support\Facades\DB;

/**
 * A pure read view for the unit detail page: how the unit has moved through the
 * pipeline (reservations, shortlists, deals) and the money collected against it.
 *
 * Payments hang off the client-project, not the unit — the sold unit is stamped
 * on client_projects.unit_id at win time, so "payments for this unit" are the
 * versements/schedule of the project(s) that bought it. Money is summed in the
 * DB (server is the source of truth); the caller decides whether to expose it.
 */
class BuildUnitInsights
{
    /**
     * @return array<string, mixed>
     */
    public function handle(Unit $unit, bool $includePayments = true): array
    {
        $reservations = Reservation::query()->where('unit_id', $unit->id);
        $timesShortlisted = ShortlistItem::query()
            ->where('shortlistable_type', 'unit')
            ->where('shortlistable_id', $unit->id)
            ->count();
        $dealAppearances = DealItem::query()->where('unit_id', $unit->id)->count();

        $data = [
            'stats' => [
                'sale_status' => $unit->sale_status?->value,
                'price_semi_fini' => $unit->price_semi_fini,
                'price_fini' => $unit->price_fini,
                'area_sqm' => $unit->area_sqm,
                'reservations' => (clone $reservations)->count(),
                'has_active_hold' => (clone $reservations)->where('hold_status', 'active')->exists(),
                // Distinct client projects holding it now — the "Interested N" counter.
                'interested_count' => (clone $reservations)
                    ->where('hold_status', 'active')
                    ->whereNotNull('client_project_id')
                    ->distinct()
                    ->count('client_project_id'),
                'reserved_expires_at' => $unit->reserved_expires_at?->toIso8601String(),
                'times_shortlisted' => $timesShortlisted,
                'deals' => $dealAppearances,
            ],
        ];

        if ($includePayments) {
            $data['payments'] = $this->payments($unit);
        }

        return $data;
    }

    /**
     * The collected/scheduled figures for THIS unit only — schedules and
     * versements carry unit_id (each apartment on a deal is tracked alone), so
     * the money here never mixes in another apartment sold on the same project.
     *
     * @return array<string, mixed>
     */
    private function payments(Unit $unit): array
    {
        // The buying project: the one whose won deal sold this unit (per-unit
        // close), falling back to the legacy stamp for old single-unit wins.
        $buyingProjectId = DealItem::query()->active()
            ->where('unit_id', $unit->id)
            ->where('state', 'won')
            ->whereHas('deal', fn ($q) => $q->where('status', 'active'))
            ->with('deal:id,client_project_id')
            ->latest('id')
            ->first()?->deal?->client_project_id;

        $primary = ClientProject::query()
            ->when($buyingProjectId !== null, fn ($q) => $q->whereKey($buyingProjectId))
            ->when($buyingProjectId === null, fn ($q) => $q->where('unit_id', $unit->id))
            ->with('client:id,first_name,last_name')
            ->latest('id')
            ->first();

        $unitScope = fn ($query) => $query->active()
            ->where('unit_id', $unit->id)
            ->when($primary !== null, fn ($q) => $q->where('client_project_id', $primary->id));

        $collected = $unitScope(Versement::query())->sum('amount');
        $scheduleTotal = $unitScope(PaymentSchedule::query())->sum('amount');
        $schedulePaid = $unitScope(PaymentSchedule::query())->sum('paid_amount');

        return [
            'project_id' => $primary?->id,
            // The project detail route is nested under the client, so the link
            // needs both ids (route params: { id: client, projectId: project }).
            'client_id' => $primary?->client_id,
            'client_name' => $primary?->client?->full_name,
            // This unit's own agreed price — drives the embedded PaymentsPanel's
            // schedule builder (planned vs total).
            'total_price' => $primary?->agreedPriceForUnit($unit->id),
            'has_data' => $primary !== null,
            'collected' => $this->money($collected),
            'schedule_total' => $this->money($scheduleTotal),
            'schedule_paid' => $this->money($schedulePaid),
            'balance' => $this->money((float) $scheduleTotal - (float) $schedulePaid),
        ];
    }

    private function money(mixed $value): string
    {
        return number_format((float) ($value ?: 0), 2, '.', '');
    }
}
