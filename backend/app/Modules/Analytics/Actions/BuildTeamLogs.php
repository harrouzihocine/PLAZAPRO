<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Actions;

use App\Modules\Clients\Enums\DealState;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Clients\Models\DealItem;
use App\Modules\Pipeline\Models\Call;
use App\Modules\Pipeline\Models\NextAction;
use App\Modules\Pipeline\Models\Visit;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The company-wide Team Logs feed — every user's rapports and planned work,
 * gated by logs.view_all. Unlike the personal dashboard this crosses ALL
 * visibility scopes on purpose: it is the manager/admin view of who did what.
 *
 * A "log" is a completed rapport: a logged call, or a conducted office / in-site
 * visit. `mode=upcoming` flips the feed to planned work instead (scheduled
 * visits + pending next-actions); `mode=all` merges both into one agenda-style
 * feed (each row carries a `planned` flag) — the phone default, where an agent
 * opens on "today + what's ahead". Read-only: it never mutates domain data.
 */
class BuildTeamLogs
{
    /** Rows scanned per source before the in-memory merge — a safety cap. */
    private const SCAN_CAP = 1000;

    private const PER_PAGE = 50;

    /**
     * @param  array<string, mixed>  $filters
     * @return array{summary: array<string, int>, data: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    public function handle(array $filters): array
    {
        $userId = isset($filters['user_id']) && $filters['user_id'] !== '' ? (int) $filters['user_id'] : null;
        $type = $filters['type'] ?? null;
        $mode = in_array($filters['mode'] ?? null, ['upcoming', 'all'], true) ? $filters['mode'] : 'logged';
        $from = ! empty($filters['from']) ? Carbon::parse($filters['from'])->startOfDay() : null;
        $to = ! empty($filters['to']) ? Carbon::parse($filters['to'])->endOfDay() : null;
        $page = max(1, (int) ($filters['page'] ?? 1));

        $rows = match ($mode) {
            'upcoming' => $this->upcoming($userId, $type, $from, $to),
            'all' => $this->logged($userId, $type, $from, $to)
                ->concat($this->upcoming($userId, $type, $from, $to)),
            default => $this->logged($userId, $type, $from, $to),
        };

        // Chronological in every mode: a bounded window reads morning → evening,
        // and planned work reads soonest-first (it used to come farthest-first,
        // which buried today's due item pages deep).
        $rows = $rows->sortBy('at')->values();

        $total = $rows->count();

        return [
            'summary' => $this->summary($userId, $from, $to),
            'data' => $rows->forPage($page, self::PER_PAGE)->values()->all(),
            'meta' => [
                'current_page' => $page,
                'per_page' => self::PER_PAGE,
                'total' => $total,
                'last_page' => (int) max(1, ceil($total / self::PER_PAGE)),
            ],
        ];
    }

    /**
     * Completed rapports: logged calls + conducted office / in-site visits.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function logged(?int $userId, ?string $type, ?Carbon $from, ?Carbon $to): Collection
    {
        $calls = collect();
        if ($type === null || $type === 'call') {
            $calls = Call::query()->active()
                ->when($userId, fn ($q) => $q->where('agent_id', $userId))
                ->when($from, fn ($q) => $q->where('called_at', '>=', $from))
                ->when($to, fn ($q) => $q->where('called_at', '<=', $to))
                ->with(['agent:id,name', 'client:id,first_name,last_name'])
                ->latest('called_at')->limit(self::SCAN_CAP)->get()
                ->map(fn (Call $c) => [
                    'id' => 'call-'.$c->id,
                    'kind' => 'call',
                    'at' => $c->called_at,
                    'user' => $c->agent?->name,
                    'client' => $c->client?->full_name,
                    'detail' => ucfirst($c->direction->value),
                    'link' => $this->projectLink($c->client_id, $c->client_project_id),
                    'planned' => false,
                ]);
        }

        $visits = $this->visitRows($type, $userId, 'completed_at', $from, $to, onlyCompleted: true);

        return $calls->concat($visits);
    }

    /**
     * Planned work: scheduled (not-yet-completed) visits + pending next-actions.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function upcoming(?int $userId, ?string $type, ?Carbon $from, ?Carbon $to): Collection
    {
        $visits = $this->visitRows($type, $userId, 'scheduled_at', $from, $to, onlyCompleted: false);

        $actions = NextAction::query()->active()->pending()
            ->when($type, fn ($q) => $q->where('type', $type))
            ->when($userId, fn ($q) => $q->where('assigned_to', $userId))
            ->when($from, fn ($q) => $q->where('due_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('due_at', '<=', $to))
            ->with([
                'assignedTo:id,name',
                'subject' => fn (MorphTo $m) => $m->morphWith([ClientProject::class => ['client:id,first_name,last_name']]),
            ])
            ->orderBy('due_at')->limit(self::SCAN_CAP)->get()
            ->map(fn (NextAction $a) => [
                'id' => 'action-'.$a->id,
                'kind' => $a->type->value,
                'at' => $a->due_at,
                'user' => $a->assignedTo?->name,
                'client' => $this->clientNameOf($a->subject),
                // No free text on a NextAction — `planned` (the chip) and the
                // kind already say everything this row knows.
                'detail' => null,
                'link' => $this->subjectLink($a->subject),
                'planned' => true,
            ]);

        return $visits->concat($actions);
    }

    /**
     * Office / in-site visit rows, either completed (logged) or scheduled (upcoming).
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function visitRows(?string $type, ?int $userId, string $dateCol, ?Carbon $from, ?Carbon $to, bool $onlyCompleted): Collection
    {
        // The visit kinds this feed request covers (call is not a visit).
        $kinds = match ($type) {
            'office_visit' => ['office'],
            'in_site_visit' => ['in_site'],
            'call' => [],
            default => ['office', 'in_site'],
        };

        if ($kinds === []) {
            return collect();
        }

        return Visit::query()->active()
            ->whereIn('type', $kinds)
            ->when($onlyCompleted, fn ($q) => $q->whereNotNull('completed_at'), fn ($q) => $q->whereNull('completed_at'))
            ->when($userId, fn ($q) => $q->where('agent_id', $userId))
            ->when($from, fn ($q) => $q->where($dateCol, '>=', $from))
            ->when($to, fn ($q) => $q->where($dateCol, '<=', $to))
            ->with(['agent:id,name', 'client:id,first_name,last_name', 'unit:id,reference'])
            ->orderByDesc($dateCol)->limit(self::SCAN_CAP)->get()
            ->map(fn (Visit $v) => [
                'id' => 'visit-'.$v->id,
                'kind' => $v->type->value === 'in_site' ? 'in_site_visit' : 'office_visit',
                'at' => $v->{$dateCol},
                'user' => $v->agent?->name,
                'client' => $v->client?->full_name,
                'detail' => $v->unit?->reference,
                'link' => $this->projectLink($v->client_id, $v->client_project_id),
                'planned' => ! $onlyCompleted,
            ]);
    }

    /**
     * Per-type scorecard for the filtered range (always about logged work +
     * closed deals, independent of the feed mode).
     *
     * @return array<string, int>
     */
    private function summary(?int $userId, ?Carbon $from, ?Carbon $to): array
    {
        $calls = Call::query()->active()
            ->when($userId, fn ($q) => $q->where('agent_id', $userId))
            ->when($from, fn ($q) => $q->where('called_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('called_at', '<=', $to))
            ->count();

        $visits = fn (string $kind) => Visit::query()->active()
            ->where('type', $kind)
            ->whereNotNull('completed_at')
            ->when($userId, fn ($q) => $q->where('agent_id', $userId))
            ->when($from, fn ($q) => $q->where('completed_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('completed_at', '<=', $to))
            ->count();

        $closed = fn (DealState $state) => (int) DealItem::query()->active()
            ->where('deal_items.state', $state->value)
            ->when($from, fn ($q) => $q->where('deal_items.closed_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('deal_items.closed_at', '<=', $to))
            ->whereNotNull('deal_items.closed_at')
            ->join('deals', 'deals.id', '=', 'deal_items.deal_id')
            ->when($userId, fn ($q) => $q->where('deals.created_by', $userId))
            ->distinct()->count('deals.client_project_id');

        return [
            'calls' => $calls,
            'office_visits' => $visits('office'),
            'in_site_visits' => $visits('in_site'),
            'won' => $closed(DealState::Won),
            'lost' => $closed(DealState::Lost),
        ];
    }

    private function projectLink(?int $clientId, ?int $projectId): ?string
    {
        if ($clientId === null) {
            return null;
        }

        return $projectId !== null
            ? '/clients/'.$clientId.'/projects/'.$projectId
            : '/clients/'.$clientId;
    }

    private function clientNameOf(?object $subject): ?string
    {
        if ($subject instanceof ClientProject) {
            return $subject->client?->full_name;
        }

        return $subject?->full_name ?? null;
    }

    private function subjectLink(?object $subject): ?string
    {
        if ($subject instanceof ClientProject) {
            return '/clients/'.$subject->client_id.'/projects/'.$subject->id;
        }

        return $subject !== null ? '/clients/'.$subject->getKey() : null;
    }
}
