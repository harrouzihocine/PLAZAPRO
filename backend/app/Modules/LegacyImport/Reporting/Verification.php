<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\Reporting;

use App\Modules\LegacyImport\Support\ImportContext;
use Illuminate\Database\Query\Builder;

/**
 * §8 — the post-sync verification checklist. Expected values are computed
 * live from the STAGING data (not hardcoded), so the checks keep working
 * against fresh dumps during parallel-run. All checks compare legacy facts vs
 * legacy_map vs target rows; a failing check means the sync is wrong, not the
 * data.
 */
class Verification
{
    /** @var list<array{name: string, expected: string, actual: string, pass: bool}> */
    private array $checks = [];

    /** @var list<string> */
    private array $info = [];

    public function __construct(private readonly ImportContext $ctx) {}

    /** @return array{checks: list<array{name: string, expected: string, actual: string, pass: bool}>, info: list<string>, passed: bool} */
    public function run(): array
    {
        $this->mapCounts();
        $this->pipelineSnapshot();
        $this->openWork();
        $this->transactions();
        $this->orphans();
        $this->spotChecks();

        return [
            'checks' => $this->checks,
            'info' => $this->info,
            'passed' => ! in_array(false, array_column($this->checks, 'pass'), true),
        ];
    }

    private function check(string $name, int|string $expected, int|string $actual): void
    {
        $this->checks[] = [
            'name' => $name,
            'expected' => (string) $expected,
            'actual' => (string) $actual,
            'pass' => (string) $expected === (string) $actual,
        ];
    }

    private function mapCount(string $sourceTable, ?string $targetTable = null): int
    {
        $query = $this->ctx->target->table('legacy_map')->where('source_table', $sourceTable);
        if ($targetTable !== null) {
            $query->where('target_table', $targetTable);
        }

        return $query->count();
    }

    private function mapCounts(): void
    {
        $legacy = $this->ctx->legacy;

        $this->check('clients: legacy vs map', $legacy->table('clients')->count(), $this->mapCount('clients'));
        $this->check('projects: legacy vs map', $legacy->table('projects')->count(), $this->mapCount('projects'));
        $this->check('users: legacy vs map', $legacy->table('users')->count(), $this->mapCount('users', 'users'));
        $this->check('locations: legacy vs map', $legacy->table('estate_categories')->count(), $this->mapCount('estate_categories'));
        $this->check('units: legacy vs map', $legacy->table('estates')->count(), $this->mapCount('estates'));
        $this->check('calls: legacy vs map', $legacy->table('call_reports')->count(), $this->mapCount('call_reports'));
        $this->check('visits: legacy vs map', $legacy->table('visit_reports')->count(), $this->mapCount('visit_reports'));
        $this->check('next_actions: legacy tasks cat 1-2 vs map', $legacy->table('tasks')->whereIn('category_id', [1, 2])->count(), $this->mapCount('tasks', 'next_actions'));
        $this->check('tasks: legacy tasks cat 3/5/6 vs map', $legacy->table('tasks')->whereNotIn('category_id', [1, 2])->count(), $this->mapCount('tasks', 'tasks'));
        $this->check('desires: legacy vs map', $legacy->table('desires')->count(), $this->mapCount('desires'));
        $this->check('desires: interest aggregates vs map', $legacy->table('client_interest')->distinct()->count('client_id'), $this->mapCount('synth:desire_interests'));
        // 83 legacy rows reference visits deleted from the old CRM — they are
        // unattributable and land in the warnings ledger, not the DB.
        $attributable = $legacy->table('booked_estate')->whereNull('booking_report_id')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('visit_reports')->whereColumn('visit_reports.id', 'booked_estate.visit_report_id'))
            ->count();
        $this->check('shortlist: attributable visit-only booked_estate vs map', $attributable, $this->mapCount('booked_estate', 'shortlist_items'));
        $this->check('reservations from bookings vs map', $legacy->table('booked_estate')->whereNotNull('booking_report_id')->count(), $this->mapCount('booked_estate', 'reservations'));
        $this->check('synth shortlist from bookings vs map', $legacy->table('booked_estate')->whereNotNull('booking_report_id')->count(), $this->mapCount('synth:shortlist_from_booked'));
        $this->check('media: legacy vs map', $legacy->table('media')->count(), $this->mapCount('media'));
        $this->check('extendings: legacy vs map', $legacy->table('extending_reports')->count(), $this->mapCount('extending_reports'));

        $nonCreatorMembers = $legacy->table('project_member')
            ->join('projects', 'projects.id', '=', 'project_member.project_id')
            ->whereColumn('project_member.user_id', '!=', 'projects.created_by')
            ->count();
        $this->check('project viewers: non-creator members vs map', $nonCreatorMembers, $this->mapCount('project_member'));
    }

    private function pipelineSnapshot(): void
    {
        $legacy = $this->ctx->legacy;
        $stageMap = (array) $this->ctx->cfg('stage_map');
        $archivedLeadStage = (string) $this->ctx->cfg('archived_lead_stage');

        // §6.2 upgrades: projects with a won deal end in stage won.
        $wonProjects = $legacy->table('booking_reports')->where('status', 'accepted')->pluck('project_id')
            ->merge($legacy->table('payment_reports')->whereNull('booking_report_id')->where('estate_id', '>', 0)->pluck('project_id'))
            ->map(fn ($id) => (int) $id)->unique()->flip()->all();

        $expected = [];
        foreach ($legacy->table('projects')->get(['id', 'status', 'last_status']) as $project) {
            $archived = $project->status === 'archive';
            if ($archived) {
                $stage = $stageMap[$project->last_status ?? 'expected'] ?? 'lead';
                if ($stage === 'lead') {
                    $stage = $archivedLeadStage;
                }
            } else {
                $stage = $stageMap[$project->status] ?? 'lead';
            }
            if (isset($wonProjects[(int) $project->id])) {
                $stage = 'won';
            }
            $key = $stage.'/'.($archived ? 'archived' : 'active');
            $expected[$key] = ($expected[$key] ?? 0) + 1;
        }

        $actual = [];
        $rows = $this->ctx->target->table('client_projects')
            ->join('legacy_map', fn ($join) => $join
                ->on('legacy_map.target_id', '=', 'client_projects.id')
                ->where('legacy_map.target_table', 'client_projects'))
            ->where('legacy_map.source_table', 'projects')
            ->selectRaw('stage, status, COUNT(*) n')
            ->groupBy('stage', 'status')
            ->get();
        foreach ($rows as $row) {
            $actual[$row->stage.'/'.$row->status] = (int) $row->n;
        }

        foreach (array_unique(array_merge(array_keys($expected), array_keys($actual))) as $bucket) {
            $this->check("pipeline {$bucket}", $expected[$bucket] ?? 0, $actual[$bucket] ?? 0);
        }

        $this->check(
            'pipeline closed_to_desire_at set',
            $legacy->table('projects')->where('status', 'desires fullfiled')->count(),
            $this->ctx->target->table('client_projects')
                ->join('legacy_map', fn ($join) => $join
                    ->on('legacy_map.target_id', '=', 'client_projects.id')
                    ->where('legacy_map.target_table', 'client_projects'))
                ->where('legacy_map.source_table', 'projects')
                ->whereNotNull('closed_to_desire_at')
                ->count()
        );
    }

    private function openWork(): void
    {
        $legacy = $this->ctx->legacy;

        $this->check(
            'open next_actions (pending)',
            $legacy->table('tasks')->whereIn('category_id', [1, 2])->where('is_complete', 0)->count(),
            $this->targetMapped('next_actions', 'tasks')->where('next_actions.state', 'pending')->count()
        );
        $this->check(
            'open tasks',
            $legacy->table('tasks')->whereNotIn('category_id', [1, 2])->where('is_complete', 0)->count(),
            $this->targetMapped('tasks', 'tasks')->where('tasks.state', 'open')->count()
        );
    }

    private function transactions(): void
    {
        $legacy = $this->ctx->legacy;

        $bookedWithBooking = $legacy->table('booked_estate')->whereNotNull('booking_report_id');
        $acceptedBookings = $legacy->table('booking_reports')->where('status', 'accepted')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $directPayments = $legacy->table('payment_reports')->whereNull('booking_report_id')->where('estate_id', '>', 0)->count();
        // Direct payments whose estate still exists in legacy — only those can
        // synthesize a reservation + deal item (payment #28's estate was deleted).
        $directWithEstate = $legacy->table('payment_reports')->whereNull('booking_report_id')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('estates')->whereColumn('estates.id', 'payment_reports.estate_id'))
            ->count();

        $bookedAccepted = (clone $bookedWithBooking)->whereIn('booking_report_id', $acceptedBookings)->count();
        $bookedPending = (clone $bookedWithBooking)->whereNotIn('booking_report_id', $acceptedBookings)->count();

        $reservations = $this->ctx->target->table('reservations')
            ->join('legacy_map', fn ($join) => $join
                ->on('legacy_map.target_id', '=', 'reservations.id')
                ->where('legacy_map.target_table', 'reservations'))
            ->selectRaw('reservations.hold_status, COUNT(*) n')->groupBy('reservations.hold_status')->pluck('n', 'hold_status');

        $this->check('reservations total', $bookedAccepted + $bookedPending + $directWithEstate, (int) $reservations->sum());
        $this->check('reservations converted', $bookedAccepted + $directWithEstate, (int) ($reservations['converted'] ?? 0));
        $this->check('reservations expired', $bookedPending, (int) ($reservations['expired'] ?? 0));

        $deals = $this->ctx->target->table('deals')
            ->join('legacy_map', fn ($join) => $join
                ->on('legacy_map.target_id', '=', 'deals.id')
                ->where('legacy_map.target_table', 'deals'))
            ->where('deals.state', 'won')->count();
        $this->check('won deals', count($acceptedBookings) + $directPayments, $deals);

        $items = $this->ctx->target->table('deal_items')
            ->join('legacy_map', fn ($join) => $join
                ->on('legacy_map.target_id', '=', 'deal_items.id')
                ->where('legacy_map.target_table', 'deal_items'))
            ->count();
        $this->check('deal items', $bookedAccepted + $directWithEstate, $items);

        $itemless = $this->ctx->target->table('deals')
            ->join('legacy_map', fn ($join) => $join
                ->on('legacy_map.target_id', '=', 'deals.id')
                ->where('legacy_map.target_table', 'deals'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('deal_items')->whereColumn('deal_items.deal_id', 'deals.id'))
            ->count();
        $acceptedWithoutUnits = count($acceptedBookings) - (clone $bookedWithBooking)->whereIn('booking_report_id', $acceptedBookings)->distinct()->count('booking_report_id');
        $this->check('item-less deals (fix in app)', $acceptedWithoutUnits + ($directPayments - $directWithEstate), $itemless);

        $this->check('versements', $legacy->table('payment_reports')->count(), $this->mapCount('payment_reports', 'versements'));
        $needAmount = $this->targetMapped('versements', 'payment_reports')->where('versements.amount', 0)->count();
        $this->info[] = "Versements still needing their amount backfilled in-app: {$needAmount}.";
    }

    private function orphans(): void
    {
        // Every legacy_map target row must exist.
        $missing = 0;
        $targets = $this->ctx->target->table('legacy_map')->distinct()->pluck('target_table');
        foreach ($targets as $table) {
            $missing += $this->ctx->target->table('legacy_map')
                ->where('target_table', $table)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from($table)->whereColumn("{$table}.id", 'legacy_map.target_id'))
                ->count();
        }
        $this->check('legacy_map orphans (targets missing)', 0, $missing);

        // Every FK the importer wrote must resolve.
        $fks = [
            ['clients', 'source_id', 'dynamic_list_items'], ['clients', 'rating_id', 'dynamic_list_items'],
            ['clients', 'assigned_agent_id', 'users'], ['clients', 'created_by', 'users'],
            ['client_projects', 'client_id', 'clients'], ['client_projects', 'created_by', 'users'],
            ['client_projects', 'archive_reason_id', 'dynamic_list_items'],
            ['desires', 'client_id', 'clients'], ['desires', 'client_project_id', 'client_projects'],
            ['calls', 'client_id', 'clients'], ['calls', 'client_project_id', 'client_projects'], ['calls', 'agent_id', 'users'],
            ['visits', 'client_id', 'clients'], ['visits', 'client_project_id', 'client_projects'], ['visits', 'agent_id', 'users'],
            ['next_actions', 'subject_id', 'client_projects'], ['next_actions', 'assigned_to', 'users'],
            ['tasks', 'subject_id', 'client_projects'], ['tasks', 'assigned_to', 'users'],
            ['shortlist_items', 'client_project_id', 'client_projects'], ['shortlist_items', 'office_visit_id', 'visits'],
            ['reservations', 'unit_id', 'units'], ['reservations', 'client_project_id', 'client_projects'], ['reservations', 'held_by', 'users'],
            ['deals', 'client_project_id', 'client_projects'],
            ['deal_items', 'deal_id', 'deals'], ['deal_items', 'unit_id', 'units'],
            ['versements', 'client_project_id', 'client_projects'], ['versements', 'unit_id', 'units'],
            ['versements', 'method_id', 'dynamic_list_items'], ['versements', 'recorded_by', 'users'],
            ['units', 'location_id', 'locations'],
            ['media', 'mediable_id', 'locations'],
            ['client_project_viewers', 'client_project_id', 'client_projects'], ['client_project_viewers', 'user_id', 'users'],
        ];
        $broken = 0;
        foreach ($fks as [$table, $column, $ref]) {
            $broken += $this->ctx->target->table($table)
                ->whereNotNull($column)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from($ref)->whereColumn("{$ref}.id", "{$table}.{$column}"))
                ->count();
        }
        $this->check('broken foreign keys on imported tables', 0, $broken);
    }

    /** §8 — five sample clients for a manual end-to-end check against the old UI. */
    private function spotChecks(): void
    {
        $sample = $this->ctx->legacy->table('clients')
            ->join('projects', 'projects.client_id', '=', 'clients.id')
            ->join('call_reports', 'call_reports.project_id', '=', 'projects.id')
            ->selectRaw('clients.id, clients.name, COUNT(call_reports.id) calls')
            ->groupBy('clients.id', 'clients.name')
            ->orderByDesc('calls')->limit(5)->get();

        foreach ($sample as $client) {
            $targetId = $this->ctx->mapId('clients', $client->id);
            if ($targetId === null) {
                $this->info[] = "Spot-check: legacy client #{$client->id} '{$client->name}' is NOT mapped!";

                continue;
            }
            $projects = $this->ctx->target->table('client_projects')->where('client_id', $targetId)->count();
            $calls = $this->ctx->target->table('calls')->where('client_id', $targetId)->count();
            $visits = $this->ctx->target->table('visits')->where('client_id', $targetId)->count();
            $this->info[] = "Spot-check '{$client->name}' (legacy #{$client->id} → #{$targetId}): {$projects} projects, {$calls} calls, {$visits} visits — compare with the old UI.";
        }
    }

    private function targetMapped(string $table, string $sourceTable): Builder
    {
        return $this->ctx->target->table($table)
            ->join('legacy_map', fn ($join) => $join
                ->on('legacy_map.target_id', '=', "{$table}.id")
                ->where('legacy_map.target_table', $table))
            ->where('legacy_map.source_table', $sourceTable);
    }
}
