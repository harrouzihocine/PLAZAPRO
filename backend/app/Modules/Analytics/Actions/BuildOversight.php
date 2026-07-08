<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Actions;

use App\Modules\Clients\Enums\ClientProjectStage;
use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Clients\Models\Deal;
use App\Modules\Payments\Models\Versement;
use App\Modules\Pipeline\Models\NextAction;
use App\Modules\Pipeline\Models\Visit;
use App\Modules\Settings\Models\User;
use App\Modules\Settings\Models\UserDraft;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\DB;

/**
 * The read side behind the Team-Oversight center: pure queries that surface
 * anomalies / lazy work so managers can follow up per user. Company-wide by
 * design — each list carries a per-user breakdown, a row-level link to where the
 * problem is, and accepts `from` / `to` / `user_id` filters. Never mutates.
 *
 * @phpstan-type Filters array{from?: ?string, to?: ?string, user_id?: int|string|null}
 */
class BuildOversight
{
    /* ---- Base queries (filters applied) ---------------------------------- */

    private function emptyClientsQuery(array $f): Builder
    {
        // No grace window here: the oversight board surfaces an empty client the
        // moment it exists, matching the dashboard's my_empty_clients widget
        // (BuildDashboard) which is ungraced too. The 48h grace lives only in the
        // FlagEmptyClients reminder, so a fresh capture is not *nagged* immediately
        // but is still *visible* to a manager reviewing client quality.
        $q = Client::query()->active()
            ->doesntHave('projects')->doesntHave('calls')->doesntHave('desire');

        return $this->filter($q, $f, 'created_at', 'created_by');
    }

    private function noNameClientsQuery(array $f): Builder
    {
        $q = Client::query()->active()
            ->where(fn (Builder $w) => $w->whereNull('first_name')->orWhere('first_name', ''))
            ->where(fn (Builder $w) => $w->whereNull('last_name')->orWhere('last_name', ''));

        return $this->filter($q, $f, 'created_at', 'created_by');
    }

    private function stuckProjectsQuery(array $f): Builder
    {
        $q = ClientProject::query()->active()
            ->whereIn('stage', [
                ClientProjectStage::Lead->value,
                ClientProjectStage::Negotiating->value,
                ClientProjectStage::Deal->value,
            ])
            ->where('created_at', '<', now()->subDays(3))
            ->whereDoesntHave('nextActions', fn (Builder $n) => $n->where('state', 'pending'));

        return $this->filter($q, $f, 'created_at', 'created_by');
    }

    private function overdueActionsQuery(array $f): Builder
    {
        return $this->filter(NextAction::query()->active()->overdue(), $f, 'due_at', 'assigned_to');
    }

    private function lostPaidDealsQuery(array $f): Builder
    {
        // A refunded versement is settled — it no longer flags the deal.
        $q = Deal::query()
            ->where('state', 'lost')
            ->whereHas('clientProject.versements', fn (Builder $v) => $v
                ->where('status', 'active')
                ->whereNull('refunded_at'));

        return $this->filter($q, $f, 'created_at', 'created_by');
    }

    private function upcomingOfficeVisitsQuery(array $f): Builder
    {
        // Scheduled, not-yet-held office visits still on a live project (or a
        // project-less qualifying visit) — the pool a manager organises from.
        // "Upcoming" = today onward; anything left open in the past is a stale
        // visit, surfaced by staleVisits. Filters keyed on schedule + agent.
        $q = Visit::query()->active()
            ->where('type', 'office')
            ->whereNull('completed_at')
            ->where('scheduled_at', '>=', now()->startOfDay())
            ->where(fn (Builder $w) => $w
                ->whereNull('client_project_id')
                ->orWhereHas('clientProject', fn (Builder $p) => $p
                    ->where('status', 'active')->whereNull('frozen_at')));

        return $this->filter($q, $f, 'scheduled_at', 'agent_id');
    }

    private function staleVisitsQuery(array $f): Builder
    {
        // Open (scheduled, uncompleted) visits on a project whose thread already
        // concluded — won, frozen, archived or removed. Nothing should stay
        // "Scheduled" once the deal decided: each must be completed (still
        // possible on a won project) or the project unfrozen / reactivated.
        $q = Visit::query()->active()
            ->whereNull('completed_at')
            ->whereHas('clientProject', fn (Builder $p) => $p
                ->where(fn (Builder $w) => $w
                    ->where('stage', 'won')
                    ->orWhereNotNull('frozen_at')
                    ->orWhere('status', '!=', 'active')));

        return $this->filter($q, $f, 'scheduled_at', 'agent_id');
    }

    /* ---- Reports (total + per-user + linked items) ----------------------- */

    /** Clients captured then abandoned — no project, no call, no desire. */
    public function emptyClients(array $f = []): array
    {
        return $this->clientReport($this->emptyClientsQuery($f));
    }

    /** Clients captured with only a phone — no name/identity. */
    public function noNameClients(array $f = []): array
    {
        return $this->clientReport($this->noNameClientsQuery($f));
    }

    /** Active projects going cold: no pending next action, opened >3 days ago. */
    public function stuckProjects(array $f = []): array
    {
        $q = $this->stuckProjectsQuery($f);

        $items = (clone $q)
            ->with(['client:id,first_name,last_name', 'creator:id,name', 'location:id,name'])
            ->latest()->limit(200)->get()
            ->map(fn (ClientProject $p) => [
                'id' => $p->id,
                'link' => ['client_id' => $p->client_id, 'project_id' => $p->id],
                'client' => $p->client?->full_name,
                'location' => $p->location?->name,
                'step' => $p->deriveStep(),
                'created_by' => $p->creator?->name,
                'created_at' => $p->created_at,
            ])->all();

        return ['total' => (clone $q)->count(), 'by_user' => $this->byUser((clone $q), 'created_by'), 'items' => $items];
    }

    /** Next actions past their due date, still pending (not respected), per assignee. */
    public function overdueActions(array $f = []): array
    {
        $q = $this->overdueActionsQuery($f);

        $items = (clone $q)
            ->with(['assignedTo:id,name', 'subject' => fn (MorphTo $s) => $s
                ->morphWith([ClientProject::class => ['client:id,first_name,last_name']])])
            ->orderBy('due_at')->limit(200)->get()
            ->map(fn (NextAction $a) => [
                'id' => $a->id,
                'link' => $this->subjectLink($a->subject),
                'client' => $this->subjectClient($a->subject),
                'type' => $a->type->value,
                'due_at' => $a->due_at,
                'assigned_to' => $a->assignedTo?->name,
            ])->all();

        return ['total' => (clone $q)->count(), 'by_user' => $this->byUser((clone $q), 'assigned_to'), 'items' => $items];
    }

    /** Office visits scheduled from today onward and not yet held — the queue to organise. */
    public function upcomingOfficeVisits(array $f = []): array
    {
        $q = $this->upcomingOfficeVisitsQuery($f);

        $items = (clone $q)
            ->with([
                'agent:id,name',
                'clientProject:id,client_id',
                'clientProject.client:id,first_name,last_name',
                'client:id,first_name,last_name',
            ])
            ->orderBy('scheduled_at')->limit(200)->get()
            ->map(fn (Visit $v) => [
                'id' => $v->id,
                'link' => ['client_id' => $v->client_id, 'project_id' => $v->client_project_id],
                'client' => ($v->clientProject?->client ?? $v->client)?->full_name,
                'project_step' => $v->clientProject?->deriveStep(),
                'scheduled_at' => $v->scheduled_at,
                'agent' => $v->agent?->name,
            ])->all();

        return ['total' => (clone $q)->count(), 'by_user' => $this->byUser((clone $q), 'agent_id'), 'items' => $items];
    }

    /** Deals marked lost whose project still carries recorded payments (refund risk). */
    public function lostPaidDeals(array $f = []): array
    {
        $q = $this->lostPaidDealsQuery($f);

        $items = (clone $q)
            ->with(['clientProject.client:id,first_name,last_name', 'creator:id,name'])
            ->latest()->limit(200)->get()
            ->map(function (Deal $d) {
                $collected = Versement::query()->active()
                    ->whereNull('refunded_at')
                    ->where('client_project_id', $d->client_project_id)->sum('amount');

                return [
                    'id' => $d->id,
                    'link' => ['client_id' => $d->clientProject?->client_id, 'project_id' => $d->client_project_id],
                    'client' => $d->clientProject?->client?->full_name,
                    'collected' => number_format((float) ($collected ?: 0), 2, '.', ''),
                    'created_by' => $d->creator?->name,
                    'created_at' => $d->created_at,
                ];
            })->all();

        return ['total' => (clone $q)->count(), 'by_user' => $this->byUser((clone $q), 'created_by'), 'items' => $items];
    }

    /** Visits still open on a project whose deal already concluded — lingering "Scheduled". */
    public function staleVisits(array $f = []): array
    {
        $q = $this->staleVisitsQuery($f);

        $items = (clone $q)
            ->with(['agent:id,name', 'clientProject:id,client_id,stage,status,closed_to_desire_at', 'clientProject.client:id,first_name,last_name'])
            ->orderBy('scheduled_at')->limit(200)->get()
            ->map(fn (Visit $v) => [
                'id' => $v->id,
                'link' => ['client_id' => $v->client_id, 'project_id' => $v->client_project_id],
                'client' => $v->clientProject?->client?->full_name,
                'type' => $v->type?->value,
                'project_step' => $v->clientProject?->deriveStep(),
                'scheduled_at' => $v->scheduled_at,
                'agent' => $v->agent?->name,
            ])->all();

        return ['total' => (clone $q)->count(), 'by_user' => $this->byUser((clone $q), 'agent_id'), 'items' => $items];
    }

    /** Users sitting on unsaved drafts (server-mirrored metadata). */
    public function drafts(array $f = []): array
    {
        // Capped at 200 like every other monitor — the chips + total still cover
        // the full set, so a runaway draft count can't ship an unbounded payload.
        $q = $this->filter(UserDraft::query(), $f, 'updated_at', 'user_id');

        $items = (clone $q)->with('user:id,name')->orderByDesc('updated_at')->limit(200)->get()
            ->map(fn (UserDraft $d) => [
                'id' => $d->id,
                'path' => $d->route, // the page the draft lived on
                'user' => $d->user?->name,
                'label' => $d->label,
                'updated_at' => $d->updated_at,
            ])->all();

        return ['total' => (clone $q)->count(), 'by_user' => $this->byUser((clone $q), 'user_id'), 'items' => $items];
    }

    /**
     * Bare totals for the sidebar badges (no items / per-user work).
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        return [
            'clients' => $this->emptyClientsQuery([])->count() + $this->noNameClientsQuery([])->count(),
            'pipeline' => $this->stuckProjectsQuery([])->count() + $this->overdueActionsQuery([])->count(),
            'deals' => $this->lostPaidDealsQuery([])->count() + $this->staleVisitsQuery([])->count(),
            'drafts' => UserDraft::query()->count(),
        ];
    }

    /* ---- Helpers --------------------------------------------------------- */

    /** Deep-link parts for a next-action's subject (client or project), or null. */
    private function subjectLink(mixed $subject): ?array
    {
        return match (true) {
            $subject instanceof ClientProject => ['client_id' => $subject->client_id, 'project_id' => $subject->id],
            $subject instanceof Client => ['client_id' => $subject->id, 'project_id' => null],
            default => null,
        };
    }

    /** The client behind a next-action's subject (its own client, or the project's). */
    private function subjectClient(mixed $subject): ?string
    {
        return match (true) {
            $subject instanceof ClientProject => $subject->client?->full_name,
            $subject instanceof Client => $subject->full_name,
            default => null,
        };
    }

    private function filter(Builder $q, array $f, string $dateColumn, ?string $userColumn): Builder
    {
        if (! empty($f['from'])) {
            $q->whereDate($dateColumn, '>=', $f['from']);
        }
        if (! empty($f['to'])) {
            $q->whereDate($dateColumn, '<=', $f['to']);
        }
        if (! empty($f['user_id']) && $userColumn !== null) {
            $q->where($userColumn, $f['user_id']);
        }

        return $q;
    }

    /**
     * Per-creator/assignee counts for a query on a column holding a user id.
     *
     * @return list<array{user_id: int|null, name: string, count: int}>
     */
    private function byUser(Builder $query, string $column): array
    {
        $counts = $query->select($column, DB::raw('COUNT(*) as total'))
            ->groupBy($column)->pluck('total', $column);

        $names = User::query()->whereIn('id', $counts->keys()->filter())->pluck('name', 'id');

        return $counts->map(fn ($total, $id) => [
            'user_id' => $id,
            'name' => $id ? ($names[$id] ?? '—') : 'Unassigned',
            'count' => (int) $total,
        ])->values()->all();
    }

    /**
     * Shared shape for the two client-quality monitors.
     *
     * @return array{total: int, by_user: list<mixed>, items: list<mixed>}
     */
    private function clientReport(Builder $query): array
    {
        $items = (clone $query)
            ->with('creator:id,name')
            ->latest()->limit(200)->get()
            ->map(fn (Client $c) => [
                'id' => $c->id,
                'link' => ['client_id' => $c->id, 'project_id' => null],
                'name' => $c->full_name,
                'phone' => $c->phone,
                'created_by' => $c->creator?->name,
                'created_at' => $c->created_at,
            ])->all();

        return [
            'total' => (clone $query)->count(),
            'by_user' => $this->byUser((clone $query), 'created_by'),
            'items' => $items,
        ];
    }
}
