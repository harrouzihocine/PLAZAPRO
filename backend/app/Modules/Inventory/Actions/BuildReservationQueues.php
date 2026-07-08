<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Enums\SaleStatus;
use App\Modules\Inventory\Models\Reservation;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Settings\Models\User;

/**
 * The reservation follow-up board: every active unit that is Reserved or holds
 * live interest reservations, each with its FULL ordered queue (the deposit
 * holder first, then backups oldest-first) — the page an agent opens to tell a
 * client "you are 2nd in line; if the 1st doesn't buy, it goes to you".
 *
 * Queue ORDER is never hidden, but client identity is only revealed for
 * projects the caller can actually see (ClientProject::isVisibleTo — the same
 * rule as the unit page's project logs); every other entry stays position +
 * holding agent, so another agent's client is never leaked.
 */
class BuildReservationQueues
{
    /** Hard cap — the live reserved/held set is small; never ship unbounded. */
    private const MAX_UNITS = 300;

    /**
     * @param  array{location_id?: mixed, status?: ?string, search?: ?string}  $filters
     * @return list<array<string, mixed>>
     */
    public function handle(User $user, array $filters = []): array
    {
        $units = Unit::query()
            ->active()
            ->where('sale_status', '!=', SaleStatus::Sold->value)
            ->where(fn ($q) => $q
                ->where('sale_status', SaleStatus::Reserved->value)
                ->orWhereHas('activeReservations', fn ($r) => $r->whereNotNull('client_project_id')))
            ->when(! empty($filters['location_id']), fn ($q) => $q->where('location_id', $filters['location_id']))
            ->when(
                in_array($filters['status'] ?? null, [SaleStatus::Reserved->value, SaleStatus::Interested->value], true),
                fn ($q) => $q->where('sale_status', $filters['status']),
            )
            ->when(
                ! empty($filters['search']),
                fn ($q) => $q->where('reference', 'like', '%'.trim((string) $filters['search']).'%'),
            )
            ->with([
                'location:id,name',
                'roomNumber',
                'floor',
                'activeReservations.holder:id,name',
                // created_by / assigned_agent_id must ride along: isVisibleTo →
                // isClientOwner reads them, and missing columns read as null.
                'activeReservations.clientProject.client:id,first_name,last_name,created_by,assigned_agent_id',
            ])
            // Deposit-locked units first (the clock is running), soonest deadline
            // on top; then the interested queues.
            ->orderByRaw('FIELD(sale_status, ?, ?)', [SaleStatus::Reserved->value, SaleStatus::Interested->value])
            ->orderByRaw('reserved_expires_at IS NULL, reserved_expires_at')
            ->orderBy('reference')
            ->limit(self::MAX_UNITS)
            ->get();

        return $units
            ->map(fn (Unit $unit) => [
                'id' => $unit->id,
                'reference' => $unit->reference,
                'sale_status' => $unit->sale_status?->value,
                'reserved_expires_at' => $unit->reserved_expires_at?->toIso8601String(),
                'location' => [
                    'id' => $unit->location?->id,
                    'name' => $unit->location?->name,
                ],
                'room_number' => $unit->roomNumber?->localizedLabel(),
                'floor' => $unit->floor?->localizedLabel(),
                'area_sqm' => $unit->area_sqm,
                'price' => $unit->price,
                'queue' => $unit->reservationQueue()
                    ->values()
                    ->map(fn (Reservation $hold, int $i) => $this->entry($unit, $hold, $i + 1, $user))
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * One queue entry. Identity is masked unless the caller can see the project.
     *
     * @return array<string, mixed>
     */
    private function entry(Unit $unit, Reservation $hold, int $position, User $user): array
    {
        $project = $hold->clientProject;
        $visible = $project !== null && $project->isVisibleTo($user);

        return [
            'position' => $position,
            'held_at' => $hold->held_at?->toIso8601String(),
            'expires_at' => $hold->expires_at?->toIso8601String(),
            'agent' => $hold->holder?->name,
            // The entry that carries the Reserved deposit lock (always #1).
            'is_deposit' => $unit->sale_status === SaleStatus::Reserved
                && (int) $hold->client_project_id === (int) $unit->reserved_project_id,
            'visible' => $visible,
            'client_project_id' => $visible ? (int) $hold->client_project_id : null,
            'client_id' => $visible ? $project->client_id : null,
            'client_name' => $visible ? ($project->client?->full_name ?: null) : null,
        ];
    }
}
