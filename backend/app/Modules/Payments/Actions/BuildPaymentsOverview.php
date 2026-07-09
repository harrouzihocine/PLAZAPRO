<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Clients\Enums\DealState;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Clients\Models\DealItem;
use App\Modules\Inventory\Enums\SaleStatus;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Payments\Enums\ScheduleState;
use App\Modules\Payments\Models\PaymentSchedule;
use App\Modules\Payments\Models\Versement;
use App\Modules\Payments\Support\Money;
use App\Modules\Settings\Models\User;

/**
 * The cross-project payment follow-up view backing the /payments page. Three
 * lists in one read:
 *  - reserved:   units Reserved (deposit paid) with the deposit collected and a
 *                live expiry countdown;
 *  - interested: units with ≥1 interested project (the "Interested N" counter);
 *  - due:        the instalments to chase — overdue / partial / soon-due —
 *                across the projects the caller may see.
 *
 * Inventory rows (reserved/interested) are unit-level, so client identity is
 * never exposed here; the instalment rows are scoped to the caller's visible
 * projects (they carry a client link).
 */
class BuildPaymentsOverview
{
    /**
     * @return array<string, mixed>
     */
    public function handle(User $user): array
    {
        $reserved = Unit::query()->active()
            ->where('sale_status', SaleStatus::Reserved->value)
            ->with(['location:id,name', 'reservedProject:id,client_id'])
            ->orderBy('reserved_expires_at')
            ->get(['id', 'reference', 'price_semi_fini', 'price_fini', 'location_id', 'reserved_expires_at', 'reserved_project_id'])
            ->map(function (Unit $unit) {
                // The open deal item lets the page declare the sale from
                // here (the same win path the deal panel uses).
                $item = $this->openDealItemFor((int) $unit->reserved_project_id, $unit->id);

                return [
                    'id' => $unit->id,
                    'reference' => $unit->reference,
                    'location' => $unit->location?->name,
                    // The finish the open deal committed to prices the follow-up.
                    'price' => (string) $unit->priceFor($item?->finish_type ?? $unit->defaultFinish()),
                    'project_id' => $unit->reserved_project_id,
                    'client_id' => $unit->reservedProject?->client_id,
                    'deal_id' => $item?->deal_id,
                    'item_id' => $item?->id,
                    'reserved_expires_at' => $unit->reserved_expires_at?->toIso8601String(),
                    'deposit' => $this->depositFor((int) $unit->reserved_project_id, $unit->id),
                ];
            })
            ->all();

        $interested = Unit::query()->active()
            ->where('sale_status', SaleStatus::Interested->value)
            ->with(['location:id,name', 'activeReservations:id,unit_id,client_project_id'])
            ->orderBy('reference')
            ->get(['id', 'reference', 'price_semi_fini', 'price_fini', 'location_id'])
            ->map(fn (Unit $unit) => [
                'id' => $unit->id,
                'reference' => $unit->reference,
                'location' => $unit->location?->name,
                'price' => (string) $unit->displayPrice(),
                'interested_count' => $unit->activeReservations
                    ->pluck('client_project_id')->filter()->unique()->count(),
            ])
            ->all();

        $visibleProjectIds = ClientProject::query()->visibleTo($user)->pluck('id');

        $due = PaymentSchedule::query()->active()
            ->whereIn('client_project_id', $visibleProjectIds)
            ->whereIn('state', [
                ScheduleState::Overdue->value,
                ScheduleState::Partial->value,
                ScheduleState::Pending->value,
            ])
            ->where('due_date', '<=', now()->addDays(30)->toDateString())
            ->with(['clientProject:id,client_id', 'unit:id,reference'])
            ->orderBy('due_date')
            ->get()
            ->map(fn (PaymentSchedule $s) => [
                'id' => $s->id,
                'installment_no' => $s->installment_no,
                'due_date' => $s->due_date?->toDateString(),
                'amount' => (string) $s->amount,
                'paid_amount' => (string) $s->paid_amount,
                'balance' => Money::sub((string) $s->amount, (string) $s->paid_amount),
                'state' => $s->state?->value,
                'unit' => $s->unit?->reference,
                'unit_id' => $s->unit_id,
                'client_id' => $s->clientProject?->client_id,
                'project_id' => $s->client_project_id,
                'link' => $s->clientProject?->client_id !== null
                    ? '/clients/'.$s->clientProject->client_id.'/projects/'.$s->client_project_id
                    : null,
            ])
            ->all();

        $overdueTotal = collect($due)
            ->where('state', ScheduleState::Overdue->value)
            ->reduce(fn (string $sum, array $row) => Money::add($sum, $row['balance']), '0.00');

        return [
            'reserved' => $reserved,
            'interested' => $interested,
            'due' => $due,
            'totals' => [
                'reserved' => count($reserved),
                'interested' => count($interested),
                'overdue' => collect($due)->where('state', ScheduleState::Overdue->value)->count(),
                'overdue_amount' => $overdueTotal,
            ],
        ];
    }

    /** The holder project's open deal item on this unit (to win it). */
    private function openDealItemFor(int $projectId, int $unitId): ?DealItem
    {
        if ($projectId <= 0) {
            return null;
        }

        return DealItem::query()->active()
            ->where('unit_id', $unitId)
            ->where('state', DealState::Open->value)
            ->whereHas('deal', fn ($q) => $q
                ->where('client_project_id', $projectId)
                ->where('status', 'active')
                ->where('state', DealState::Open->value))
            ->latest('id')
            ->first();
    }

    /** Total collected for a unit under its holder project (the deposit paid). */
    private function depositFor(int $projectId, int $unitId): string
    {
        if ($projectId <= 0) {
            return '0.00';
        }

        $paid = Versement::query()->active()
            ->where('client_project_id', $projectId)
            ->where('unit_id', $unitId)
            ->get(['amount', 'refunded_at'])
            ->reject->isRefunded()
            ->pluck('amount');

        return Money::sum($paid);
    }
}
