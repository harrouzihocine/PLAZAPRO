<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Actions;

use App\Modules\Clients\Enums\DealState;
use App\Modules\Clients\Enums\ShortlistState;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Clients\Models\DealItem;
use App\Modules\Clients\Models\ShortlistItem;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Pipeline\Enums\VisitType;
use App\Modules\Pipeline\Models\Call;
use App\Modules\Pipeline\Models\Visit;
use App\Modules\Settings\Models\DynamicListItem;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The "Voice of Client" read view for a development (Location) — or a single unit
 * drill-down. It mines the logs the team already captures (call topics/objections,
 * visit checklist/objections, in-site outcomes, per-unit shortlist states, lost
 * reasons) into an actionable picture: the engagement funnel, the top objections,
 * why deals were lost, which units are demanded vs rejected, sentiment, an activity
 * trend, recent verbatims, and rule-based recommended actions.
 *
 * Pure read side (mirrors BuildDashboard / BuildLocationInsights): it never writes.
 *
 * Attribution rule: a call has no unit, so call objections roll up to the whole
 * development; an in-site visit carries a unit_id, so its objections attribute to
 * that specific unit — the unit drill-down therefore uses visits only.
 */
class BuildLocationFeedback
{
    private ?Carbon $from = null;

    private ?Carbon $to = null;

    /**
     * @param  array{from?: ?string, to?: ?string}  $filters
     * @return array<string, mixed>
     */
    public function handle(Location $location, array $filters = [], ?Unit $unit = null): array
    {
        $this->from = ! empty($filters['from']) ? Carbon::parse($filters['from'])->startOfDay() : null;
        $this->to = ! empty($filters['to']) ? Carbon::parse($filters['to'])->endOfDay() : null;

        $isUnitScope = $unit !== null;

        $units = Unit::query()->active()->where('location_id', $location->id)
            ->get(['id', 'reference', 'price', 'sale_status']);
        if ($isUnitScope) {
            $units = $units->where('id', $unit->id)->values();
        }
        $unitIds = $units->pluck('id')->all();

        // Every project riding on this development (all lifecycle states — lost ones
        // carry the "why lost" reason we want).
        $projectIds = ClientProject::query()->where('location_id', $location->id)->pluck('id')->all();

        // Base query builders honouring scope + the optional date window.
        $callBase = fn (): Builder => Call::query()->active()
            ->whereIn('client_project_id', $projectIds)
            ->when($this->from, fn ($q) => $q->where('called_at', '>=', $this->from))
            ->when($this->to, fn ($q) => $q->where('called_at', '<=', $this->to));

        $visitBase = fn (): Builder => Visit::query()->active()
            ->when($isUnitScope, fn ($q) => $q->where('unit_id', $unit->id))
            ->when(! $isUnitScope, fn ($q) => $q->whereIn('client_project_id', $projectIds))
            ->when($this->from, fn ($q) => $q->where('scheduled_at', '>=', $this->from))
            ->when($this->to, fn ($q) => $q->where('scheduled_at', '<=', $this->to));

        // Per-unit shortlist / visit / deal rows — fetched once, assembled in PHP
        // (a location's volume is modest; this avoids per-unit N+1 queries).
        $shortlistRows = ShortlistItem::query()->active()
            ->where('shortlistable_type', 'unit')
            ->whereIn('shortlistable_id', $unitIds)
            ->when($this->from, fn ($q) => $q->where('created_at', '>=', $this->from))
            ->when($this->to, fn ($q) => $q->where('created_at', '<=', $this->to))
            ->get(['shortlistable_id', 'state']);

        $inSiteVisitRows = (clone $visitBase())
            ->where('type', VisitType::InSite->value)
            ->whereNotNull('unit_id')
            ->whereNotNull('completed_at')
            ->get(['unit_id']);

        $dealRows = DealItem::query()->active()
            ->whereIn('unit_id', $unitIds)
            ->get(['unit_id', 'state']);

        // ── Objections (the headline) + discussion topics ──────────────────────
        $objectionIds = array_merge(
            $isUnitScope ? [] : $this->flatten($callBase(), 'objections'),
            $this->flatten($visitBase(), 'objections'),
        );
        $topicIds = array_merge(
            $isUnitScope ? [] : $this->flatten($callBase(), 'topics'),
            $this->flatten($visitBase(), 'checklist'),
        );

        // ── Funnel ─────────────────────────────────────────────────────────────
        $calls = $isUnitScope ? 0 : (clone $callBase())->count();
        $officeVisits = $isUnitScope ? 0 : (clone $visitBase())
            ->where('type', VisitType::Office->value)->whereNotNull('completed_at')->count();
        $inSiteVisits = $inSiteVisitRows->count();
        $shortlisted = $shortlistRows->count();
        // `state` is an enum-cast attribute, so compare enum-to-enum (never against
        // the raw string, which never matches).
        $stateCount = fn (ShortlistState ...$s) => $shortlistRows
            ->filter(fn ($row) => in_array($row->state, $s, true))->count();
        $interested = $stateCount(ShortlistState::VisitedInterested, ShortlistState::Won);
        $notInterested = $stateCount(ShortlistState::VisitedNotInterested, ShortlistState::Lost);
        $reserved = $dealRows->filter(fn ($d) => $d->state === DealState::Reserved)->count();
        $won = $dealRows->filter(fn ($d) => $d->state === DealState::Won)->count();

        // Compute the heavier aggregates once, then reuse them for both the payload
        // and the recommendation rules.
        $objectionsRanked = $this->rankByLabel($objectionIds);
        $lostReasons = $isUnitScope ? [] : $this->lostReasons($projectIds);
        $demandUnits = $isUnitScope ? [] : $this->demandUnits($units, $shortlistRows, $inSiteVisitRows, $dealRows);

        return [
            'scope' => $isUnitScope ? 'unit' : 'location',
            'location' => ['id' => $location->id, 'name' => $location->name],
            'unit' => $isUnitScope ? ['id' => $unit->id, 'reference' => $unit->reference] : null,
            'window' => [
                'from' => $this->from?->toDateString(),
                'to' => $this->to?->toDateString(),
            ],
            'funnel' => [
                'calls' => $calls,
                'office_visits' => $officeVisits,
                'in_site_visits' => $inSiteVisits,
                'shortlisted' => $shortlisted,
                'interested' => $interested,
                'reserved' => $reserved,
                'won' => $won,
            ],
            'objections' => $objectionsRanked,
            'topics' => $this->rankByLabel($topicIds),
            'lost_reasons' => $lostReasons,
            'sentiment' => [
                'positive' => $interested,
                'negative' => $notInterested,
                'neutral' => $stateCount(ShortlistState::Shortlisted, ShortlistState::NotVisited),
            ],
            'demand_units' => $demandUnits,
            'at_risk_units' => $isUnitScope ? [] : $this->atRiskUnits($units, $shortlistRows, $inSiteVisitRows),
            'trend' => $this->trend($callBase(), $visitBase(), $isUnitScope),
            'verbatims' => $this->verbatims($callBase(), $visitBase(), $isUnitScope),
            'recommendations' => $this->recommendations(
                $objectionsRanked,
                $lostReasons,
                ['shortlisted' => $shortlisted, 'in_site_visits' => $inSiteVisits, 'interested' => $interested, 'won' => $won, 'objections' => count($objectionIds)],
                $demandUnits,
            ),
        ];
    }

    /**
     * Flatten a JSON id-array column (objections / topics / checklist) across a
     * query into one flat list of ids.
     *
     * @return list<int>
     */
    private function flatten(Builder $query, string $column): array
    {
        return $query->whereNotNull($column)->pluck($column)
            ->flatMap(fn ($v) => is_array($v) ? $v : [])
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * Turn a flat list of dynamic-list item ids into a Pareto ranking with labels.
     *
     * @param  list<int>  $ids
     * @return list<array{id: int, label: string, count: int}>
     */
    private function rankByLabel(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $counts = array_count_values($ids);
        arsort($counts);
        $labels = DynamicListItem::query()->whereIn('id', array_keys($counts))->pluck('label', 'id');

        $out = [];
        foreach ($counts as $id => $count) {
            $out[] = ['id' => (int) $id, 'label' => $labels[$id] ?? ('#'.$id), 'count' => $count];
        }

        return $out;
    }

    /**
     * Ranked "why deals were lost" — the structured archive reason on archived /
     * lost projects of this development.
     *
     * @param  list<int>  $projectIds
     * @return list<array{id: int, label: string, count: int}>
     */
    private function lostReasons(array $projectIds): array
    {
        $ids = ClientProject::query()
            ->whereIn('id', $projectIds)
            ->whereNotNull('archive_reason_id')
            ->when($this->from, fn ($q) => $q->where('updated_at', '>=', $this->from))
            ->when($this->to, fn ($q) => $q->where('updated_at', '<=', $this->to))
            ->pluck('archive_reason_id')
            ->map(fn ($v) => (int) $v)
            ->all();

        return $this->rankByLabel($ids);
    }

    /**
     * Per-unit demand leaderboard, assembled from the pre-fetched rows.
     *
     * @param  Collection<int, Unit>  $units
     * @param  Collection<int, ShortlistItem>  $shortlistRows
     * @param  Collection<int, Visit>  $visitRows
     * @param  Collection<int, DealItem>  $dealRows
     * @return list<array<string, mixed>>
     */
    private function demandUnits(Collection $units, Collection $shortlistRows, Collection $visitRows, Collection $dealRows): array
    {
        $shortByUnit = $shortlistRows->groupBy('shortlistable_id');
        $visitsByUnit = $visitRows->groupBy('unit_id');
        $dealByUnit = $dealRows->groupBy('unit_id');

        $rows = $units->map(function (Unit $u) use ($shortByUnit, $visitsByUnit, $dealByUnit) {
            $sl = $shortByUnit->get($u->id, collect());
            $state = fn (ShortlistState ...$s) => $sl
                ->filter(fn ($row) => in_array($row->state, $s, true))->count();

            $shortlisted = $sl->count();
            $visits = $visitsByUnit->get($u->id, collect())->count();
            $interested = $state(ShortlistState::VisitedInterested, ShortlistState::Won);
            $notInterested = $state(ShortlistState::VisitedNotInterested, ShortlistState::Lost);
            $deal = $dealByUnit->get($u->id, collect());
            $seen = $interested + $notInterested;

            return [
                'id' => $u->id,
                'reference' => $u->reference,
                'price' => $u->price,
                'sale_status' => $u->sale_status?->value,
                'shortlisted' => $shortlisted,
                'visits' => $visits,
                'interested' => $interested,
                'not_interested' => $notInterested,
                'reserved' => $deal->filter(fn ($d) => $d->state === DealState::Reserved)->count(),
                'won' => $deal->filter(fn ($d) => $d->state === DealState::Won)->count(),
                // Of the clients who saw it, how many liked it (null when nobody has).
                'interest_ratio' => $seen > 0 ? round($interested / $seen, 2) : null,
                'demand' => $shortlisted + $visits,
            ];
        })
            ->filter(fn ($r) => $r['demand'] > 0)
            ->sortByDesc('demand')
            ->values();

        return $rows->all();
    }

    /**
     * Units clients see but reject — a high rejection ratio flags a pricing / finish
     * / expectation problem worth the promoteur's attention.
     *
     * @return list<array<string, mixed>>
     */
    private function atRiskUnits(Collection $units, Collection $shortlistRows, Collection $visitRows): array
    {
        $shortByUnit = $shortlistRows->groupBy('shortlistable_id');
        $visitsByUnit = $visitRows->groupBy('unit_id');

        return $units->map(function (Unit $u) use ($shortByUnit, $visitsByUnit) {
            $sl = $shortByUnit->get($u->id, collect());
            $interested = $sl->filter(fn ($r) => in_array($r->state, [ShortlistState::VisitedInterested, ShortlistState::Won], true))->count();
            $rejected = $sl->filter(fn ($r) => in_array($r->state, [ShortlistState::VisitedNotInterested, ShortlistState::Lost], true))->count();
            $seen = $interested + $rejected;

            return [
                'id' => $u->id,
                'reference' => $u->reference,
                'price' => $u->price,
                'visits' => $visitsByUnit->get($u->id, collect())->count(),
                'rejected' => $rejected,
                'seen' => $seen,
                'rejection_ratio' => $seen > 0 ? round($rejected / $seen, 2) : null,
            ];
        })
            ->filter(fn ($r) => $r['seen'] >= 2 && $r['rejection_ratio'] !== null && $r['rejection_ratio'] >= 0.5)
            ->sortByDesc('rejection_ratio')
            ->values()
            ->all();
    }

    /**
     * Weekly activity (calls + visits) over the last 8 weeks for a trend line.
     *
     * @return list<array{week: string, calls: int, visits: int}>
     */
    private function trend(Builder $callBase, Builder $visitBase, bool $isUnitScope): array
    {
        $floor = now()->startOfWeek()->subWeeks(7);

        $callWeeks = $isUnitScope ? collect() : (clone $callBase)
            ->where('called_at', '>=', $floor)->pluck('called_at')
            ->groupBy(fn ($d) => Carbon::parse($d)->startOfWeek()->toDateString())
            ->map->count();

        $visitWeeks = (clone $visitBase)
            ->where('scheduled_at', '>=', $floor)->pluck('scheduled_at')
            ->groupBy(fn ($d) => Carbon::parse($d)->startOfWeek()->toDateString())
            ->map->count();

        $out = [];
        for ($i = 0; $i < 8; $i++) {
            $week = now()->startOfWeek()->subWeeks(7 - $i)->toDateString();
            $out[] = [
                'week' => $week,
                'calls' => (int) ($callWeeks[$week] ?? 0),
                'visits' => (int) ($visitWeeks[$week] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * The most recent real client comments (non-empty notes) — the qualitative
     * voice behind the numbers.
     *
     * @return list<array<string, mixed>>
     */
    private function verbatims(Builder $callBase, Builder $visitBase, bool $isUnitScope): array
    {
        $calls = $isUnitScope ? collect() : (clone $callBase)
            ->whereNotNull('notes')->where('notes', '!=', '')
            ->with(['client:id,first_name,last_name', 'agent:id,name'])
            ->latest('called_at')->limit(10)->get()
            ->map(fn (Call $c) => [
                'type' => 'call',
                'date' => $c->called_at,
                'note' => $c->notes,
                'client' => $c->client?->full_name,
                'agent' => $c->agent?->name,
                'unit' => null,
            ]);

        $visits = (clone $visitBase)
            ->whereNotNull('notes')->where('notes', '!=', '')
            ->with(['client:id,first_name,last_name', 'agent:id,name', 'unit:id,reference'])
            ->latest('scheduled_at')->limit(10)->get()
            ->map(fn (Visit $v) => [
                'type' => $v->type === VisitType::InSite ? 'in_site_visit' : 'office_visit',
                'date' => $v->scheduled_at,
                'note' => $v->notes,
                'client' => $v->client?->full_name,
                'agent' => $v->agent?->name,
                'unit' => $v->unit?->reference,
            ]);

        return $calls->concat($visits)
            ->sortByDesc('date')
            ->take(12)
            ->values()
            ->all();
    }

    /**
     * Rule-based "what to do next" cards derived from the aggregates — the promoteur's
     * next-best-action, the way GA Insights / Einstein surface findings.
     *
     * @param  list<array{id: int, label: string, count: int}>  $ranked
     * @param  list<array{id: int, label: string, count: int}>  $lostReasons
     * @param  array<string, int>  $funnel
     * @param  list<array<string, mixed>>  $demandUnits
     * @return list<array{severity: string, title: string, body: string}>
     */
    private function recommendations(array $ranked, array $lostReasons, array $funnel, array $demandUnits): array
    {
        $out = [];
        $valueOf = fn (int $id) => DynamicListItem::query()->whereKey($id)->value('value');

        // Map ranked objections to their machine values so rules don't depend on labels.
        $byValue = [];
        foreach ($ranked as $o) {
            $byValue[$valueOf($o['id']) ?? ''] = $o['count'];
        }
        $has = fn (string $v, int $min = 2) => ($byValue[$v] ?? 0) >= $min;

        if ($ranked !== []) {
            $top = $ranked[0];
            $out[] = [
                'severity' => 'high',
                'title' => 'Top objection: '.$top['label'],
                'body' => "\"{$top['label']}\" is the #1 concern clients raise here ({$top['count']} mentions). Tackle it head-on in your pitch and marketing.",
            ];
        }

        if ($has('price_too_high') || $has('wants_more_discount')) {
            $out[] = [
                'severity' => 'high',
                'title' => 'Price is a recurring blocker',
                'body' => 'Clients repeatedly flag price. Consider a limited-time discount, a launch offer, or repackaging with a box/parking incentive.',
            ];
        }

        if ($has('payment_plan_too_short')) {
            $out[] = [
                'severity' => 'medium',
                'title' => 'Offer a longer payment plan',
                'body' => 'Several clients find the instalment window too short. A longer plan could unlock hesitant buyers.',
            ];
        }

        if ($has('location_not_preferred')) {
            $out[] = [
                'severity' => 'medium',
                'title' => 'Location is a common concern',
                'body' => 'Lead with amenities, transport links and neighbourhood value in your pitch and media to reframe the location objection.',
            ];
        }

        // Funnel leak: lots of demand, few site visits.
        if ($funnel['shortlisted'] >= 5 && $funnel['in_site_visits'] < $funnel['shortlisted'] / 2) {
            $out[] = [
                'severity' => 'medium',
                'title' => 'Interested clients aren\'t visiting the site',
                'body' => "{$funnel['shortlisted']} properties were shortlisted but only {$funnel['in_site_visits']} in-site visits happened. Tighten visit scheduling and follow-up.",
            ];
        }

        // A high-demand unit that never converts.
        foreach ($demandUnits as $u) {
            if (($u['demand'] ?? 0) >= 4 && ($u['won'] ?? 0) === 0 && ($u['interest_ratio'] !== null && $u['interest_ratio'] < 0.5)) {
                $out[] = [
                    'severity' => 'medium',
                    'title' => "Unit {$u['reference']} is seen but not selling",
                    'body' => "Unit {$u['reference']} draws interest but rarely converts. Review its price, floor or finish against comparable units.",
                ];
                break;
            }
        }

        if ($lostReasons !== []) {
            $topLost = $lostReasons[0];
            $out[] = [
                'severity' => 'info',
                'title' => 'Deals are lost mostly to: '.$topLost['label'],
                'body' => "\"{$topLost['label']}\" is the leading reason deals close as lost here ({$topLost['count']}). Address it earlier in the pipeline.",
            ];
        }

        if ($funnel['objections'] === 0) {
            $out[] = [
                'severity' => 'info',
                'title' => 'Log objections to unlock insights',
                'body' => 'No objections have been captured in this window. Ask agents to tick client concerns on calls and visits so this page can guide your next move.',
            ];
        }

        return $out;
    }
}
