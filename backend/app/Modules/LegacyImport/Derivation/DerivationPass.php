<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\Derivation;

use App\Modules\LegacyImport\Support\ImportContext;

/**
 * §6 — the post-phase derivation pass, run on every sync. Each step only
 * UPGRADES (sold > reserved > interested > available; stage → won) and only
 * touches rows whose inputs are legacy-mapped — the app owns everything else.
 */
class DerivationPass
{
    private const PRECEDENCE = ['available' => 0, 'interested' => 1, 'reserved' => 2, 'sold' => 3];

    public function __construct(private readonly ImportContext $ctx) {}

    public function run(): void
    {
        $this->ctx->target->transaction(function (): void {
            $this->deriveUnitSaleStatus();
            $this->deriveWonProjects();
            $this->upgradeShortlists();
            $this->flagExpiredDealProjects();
        });
    }

    /** §6.1 — sale_status from won items / versements / holds / is_available. */
    private function deriveUnitSaleStatus(): void
    {
        $mappedUnits = $this->mapPairs('estates', 'units'); // legacy estate id → unit id

        // Won deal items / versements targeting a unit → sold.
        $soldUnitIds = $this->ctx->target->table('deal_items')
            ->join('legacy_map', fn ($join) => $join
                ->on('legacy_map.target_id', '=', 'deal_items.id')
                ->where('legacy_map.target_table', 'deal_items'))
            ->whereIn('legacy_map.source_table', ['synth:deal_item_from_booked', 'synth:deal_item_from_payment'])
            ->where('deal_items.state', 'won')
            ->whereNotNull('deal_items.unit_id')
            ->pluck('deal_items.unit_id')
            ->merge(
                $this->ctx->target->table('versements')
                    ->join('legacy_map', fn ($join) => $join
                        ->on('legacy_map.target_id', '=', 'versements.id')
                        ->where('legacy_map.target_table', 'versements'))
                    ->where('legacy_map.source_table', 'payment_reports')
                    ->whereNotNull('versements.unit_id')
                    ->pluck('versements.unit_id')
            )->map(fn ($id) => (int) $id)->unique()->flip()->all();

        // Active mapped holds → reserved (locked decision 2026-07-06: keep the
        // plan's literal value; also claim reserved_project_id so the lock has
        // an owner). No legacy hold is active today — defensive branch.
        $activeHolds = $this->ctx->target->table('reservations')
            ->join('legacy_map', fn ($join) => $join
                ->on('legacy_map.target_id', '=', 'reservations.id')
                ->where('legacy_map.target_table', 'reservations'))
            ->whereIn('legacy_map.source_table', ['booked_estate', 'synth:reservation_from_payment'])
            ->where('reservations.hold_status', 'active')
            ->get(['reservations.unit_id', 'reservations.client_project_id'])
            ->keyBy(fn ($r) => (int) $r->unit_id);

        $availability = $this->ctx->legacy->table('estates')->pluck('is_available', 'id');

        $current = $this->ctx->target->table('units')
            ->whereIn('id', array_values($mappedUnits))
            ->pluck('sale_status', 'id');

        $fallback = (string) $this->ctx->cfg('legacy_unavailable_means');

        foreach ($mappedUnits as $legacyId => $unitId) {
            $extra = [];
            if (isset($soldUnitIds[$unitId])) {
                $derived = 'sold';
            } elseif ($activeHolds->has($unitId)) {
                $derived = 'reserved';
                $extra = ['reserved_project_id' => $activeHolds[$unitId]->client_project_id];
            } elseif ((int) ($availability[$legacyId] ?? 0) === 1) {
                $derived = 'available';
            } else {
                $derived = $fallback;
                if ($fallback === 'sold') {
                    $this->ctx->warn('unit_sold_by_fallback', "Unit #{$unitId} (legacy estate #{$legacyId}) marked sold because legacy is_available=0 with no recorded sale — review (§6.1).");
                }
            }

            $currentStatus = (string) ($current[$unitId] ?? 'available');
            if ((self::PRECEDENCE[$derived] ?? 0) > (self::PRECEDENCE[$currentStatus] ?? 0)) {
                $this->ctx->update('units', $unitId, ['sale_status' => $derived] + $extra);
                $this->ctx->count('derive', "unit_{$derived}");
            }
        }
    }

    /** §6.2 — projects with a mapped won deal → stage won + unit/location/total. */
    private function deriveWonProjects(): void
    {
        $wonDeals = $this->ctx->target->table('deals')
            ->join('legacy_map', fn ($join) => $join
                ->on('legacy_map.target_id', '=', 'deals.id')
                ->where('legacy_map.target_table', 'deals'))
            ->whereIn('legacy_map.source_table', ['synth:deal_from_booking', 'synth:deal_from_payment'])
            ->where('deals.state', 'won')
            ->get(['deals.id', 'deals.client_project_id', 'deals.total_price']);

        foreach ($wonDeals as $deal) {
            $unitId = $this->ctx->target->table('deal_items')
                ->where('deal_id', $deal->id)->where('state', 'won')
                ->orderBy('id')->value('unit_id');
            $project = $this->ctx->target->table('client_projects')
                ->where('id', $deal->client_project_id)
                ->first(['id', 'stage', 'unit_id', 'location_id', 'total_price']);
            if ($project === null) {
                continue;
            }

            $update = [];
            if ($project->stage !== 'won') {
                $update['stage'] = 'won';
            }
            if ($project->unit_id === null && $unitId !== null) {
                $update['unit_id'] = $unitId;
            }
            if ($project->location_id === null && $unitId !== null) {
                $update['location_id'] = $this->ctx->target->table('units')->where('id', $unitId)->value('location_id');
            }
            if ($project->total_price === null && $deal->total_price !== null) {
                $update['total_price'] = $deal->total_price;
            }
            if ($update !== []) {
                $this->ctx->update('client_projects', (int) $project->id, $update);
                $this->ctx->count('derive', 'project_won');
            }
        }
    }

    /** §6.3 — office-visit shortlist rows upgrade from closed deal items. */
    private function upgradeShortlists(): void
    {
        $shortlists = $this->ctx->target->table('shortlist_items')
            ->join('legacy_map', fn ($join) => $join
                ->on('legacy_map.target_id', '=', 'shortlist_items.id')
                ->where('legacy_map.target_table', 'shortlist_items'))
            ->where('legacy_map.source_table', 'booked_estate')
            ->where('shortlist_items.state', 'shortlisted')
            ->where('shortlist_items.shortlistable_type', 'unit')
            ->get(['shortlist_items.id', 'shortlist_items.client_project_id', 'shortlist_items.shortlistable_id']);

        foreach ($shortlists as $item) {
            $closedState = $this->ctx->target->table('deal_items')
                ->join('deals', 'deals.id', '=', 'deal_items.deal_id')
                ->join('legacy_map', fn ($join) => $join
                    ->on('legacy_map.target_id', '=', 'deal_items.id')
                    ->where('legacy_map.target_table', 'deal_items'))
                ->whereIn('legacy_map.source_table', ['synth:deal_item_from_booked', 'synth:deal_item_from_payment'])
                ->where('deals.client_project_id', $item->client_project_id)
                ->where('deal_items.unit_id', $item->shortlistable_id)
                ->whereIn('deal_items.state', ['won', 'lost'])
                ->value('deal_items.state');

            if ($closedState !== null) {
                $this->ctx->update('shortlist_items', (int) $item->id, ['state' => $closedState]);
                $this->ctx->count('derive', 'shortlist_upgraded');
            }
        }
    }

    /** §6.4 — deal-stage projects whose only holds expired: human decision. */
    private function flagExpiredDealProjects(): void
    {
        $mappedProjects = $this->mapPairs('projects', 'client_projects');
        if ($mappedProjects === []) {
            return;
        }

        $rows = $this->ctx->target->table('client_projects')
            ->whereIn('id', array_values($mappedProjects))
            ->where('stage', 'deal')
            ->get(['id']);

        foreach ($rows as $project) {
            $holds = $this->ctx->target->table('reservations')
                ->where('client_project_id', $project->id)
                ->pluck('hold_status');
            if ($holds->isNotEmpty() && $holds->every(fn ($s) => $s === 'expired')) {
                $this->ctx->warn('deal_project_expired_hold', "Project #{$project->id} sits in stage 'deal' but its only reservation(s) expired — decide manually (§6.4).");
            }
        }
    }

    /** @return array<int, int> legacy id → target id for one source table. */
    private function mapPairs(string $sourceTable, string $targetTable): array
    {
        return $this->ctx->target->table('legacy_map')
            ->where('source_table', $sourceTable)
            ->where('target_table', $targetTable)
            ->pluck('target_id', 'source_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
