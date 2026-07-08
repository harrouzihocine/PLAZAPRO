<?php

declare(strict_types=1);

namespace App\Modules\Settings\Actions;

use App\Core\Enums\RecordStatus;
use App\Modules\Clients\Enums\DealState;
use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Clients\Models\Deal;
use App\Modules\Payments\Models\Versement;
use App\Modules\Pipeline\Enums\NextActionType;
use App\Modules\Pipeline\Models\Call;
use App\Modules\Pipeline\Models\NextAction;
use App\Modules\Pipeline\Models\Task;
use App\Modules\Pipeline\Models\Visit;
use App\Modules\Settings\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * The offboarding report for ONE user, in two halves:
 *
 *  - career: what they did — calls, conducted visits, closed deals, recorded
 *    payments. Immutable history that stays under their name forever (users
 *    are never deleted), so analytics and audit keep telling the truth.
 *  - open book: what someone else must pick up when they leave — followed
 *    clients, live project seats, pending plans, scheduled visits, open tasks.
 *
 * The open-book query builders below are THE definition of what
 * TransferUserWork hands to the successor — the report and the transfer must
 * never disagree, so the transfer action reuses these exact builders.
 */
class BuildUserWorkload
{
    /** Mirrors the oversight lists: totals are exact, item lists are capped. */
    private const MAX_ITEMS = 200;

    /**
     * Open clients: the ones the user follows up (assigned agent), plus their
     * own captures nobody was ever assigned to — without a new owner, both
     * fall out of every active picker and quietly orphan.
     */
    public function openClientsQuery(User $user): Builder
    {
        return Client::query()->active()
            ->where(fn (Builder $q) => $q
                ->where('assigned_agent_id', $user->id)
                ->orWhere(fn (Builder $own) => $own
                    ->whereNull('assigned_agent_id')
                    ->where('created_by', $user->id)));
    }

    /**
     * Live projects that would ORPHAN without this user: every non-hidden
     * viewer seat they hold, plus the projects they created where no other
     * active colleague holds a live seat — a project with a working team is
     * not orphaned by one member leaving. That rule also keeps the transfer
     * idempotent: created_by is never rewritten (provenance), so it is the
     * successor's viewer seat that takes a creator-project off this list.
     *
     * Frozen and won projects are included on purpose — payments and
     * documents still flow on them, so they still need a person. Archived /
     * removed projects are history, not work (the archive desk re-hands them
     * on reactivation).
     */
    public function contributorProjectsQuery(User $user): Builder
    {
        return ClientProject::query()->active()
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $own) => $own
                    ->where('created_by', $user->id)
                    ->whereDoesntHave('viewers', fn (Builder $v) => $v
                        ->whereKeyNot($user->id)
                        ->whereNull('client_project_viewers.hidden_at')
                        ->where('users.is_active', true)
                        ->where('users.status', RecordStatus::Active->value)))
                ->orWhereHas('viewers', fn (Builder $v) => $v
                    ->whereKey($user->id)
                    ->whereNull('client_project_viewers.hidden_at')));
    }

    /** Planned next steps (calls / visits) sitting on the user, not yet done. */
    public function pendingActionsQuery(User $user): Builder
    {
        return NextAction::query()->active()->pending()
            ->where('assigned_to', $user->id);
    }

    /** Scheduled, not-yet-held visits the user is expected to conduct. */
    public function openVisitsQuery(User $user): Builder
    {
        return Visit::query()->active()
            ->whereNull('completed_at')
            ->where('agent_id', $user->id);
    }

    public function openTasksQuery(User $user): Builder
    {
        return Task::query()->active()->open()
            ->where('assigned_to', $user->id);
    }

    /**
     * Open-book counts only — the cheap probe behind "does deactivating this
     * user orphan anything?". No item lists, no per-project step derivation.
     */
    public function totals(User $user): array
    {
        $totals = [
            'clients' => $this->openClientsQuery($user)->count(),
            'projects' => $this->contributorProjectsQuery($user)->count(),
            'next_actions' => $this->pendingActionsQuery($user)->count(),
            'visits' => $this->openVisitsQuery($user)->count(),
            'tasks' => $this->openTasksQuery($user)->count(),
        ];

        return ['open_totals' => $totals, 'open_total' => array_sum($totals)];
    }

    public function handle(User $user): array
    {
        $open = [
            'clients' => $this->clients($user),
            'projects' => $this->projects($user),
            'next_actions' => $this->nextActions($user),
            'visits' => $this->visits($user),
            'tasks' => $this->tasks($user),
        ];

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'role' => $user->role?->name,
                'is_active' => (bool) $user->is_active,
            ],
            'career' => $this->career($user),
            'open' => $open,
            'open_total' => array_sum(array_column($open, 'total')),
        ];
    }

    /** All-time counts, cancelled history included — the full story. */
    private function career(User $user): array
    {
        return [
            'clients_created' => Client::query()->where('created_by', $user->id)->count(),
            'projects_opened' => ClientProject::query()->where('created_by', $user->id)->count(),
            'calls_logged' => Call::query()->where('agent_id', $user->id)->count(),
            'visits_conducted' => Visit::query()->whereNotNull('completed_at')->where('agent_id', $user->id)->count(),
            'deals_opened' => Deal::query()->where('created_by', $user->id)->count(),
            'deals_won' => Deal::query()->where('created_by', $user->id)
                ->where('state', DealState::Won->value)->count(),
            'payments_recorded' => Versement::query()->where('recorded_by', $user->id)->count(),
            'collected_total' => (string) Versement::query()->active()
                ->whereNull('refunded_at')
                ->where('recorded_by', $user->id)
                ->sum('amount'),
        ];
    }

    private function clients(User $user): array
    {
        $q = $this->openClientsQuery($user);

        $items = (clone $q)->latest()->limit(self::MAX_ITEMS)->get()
            ->map(fn (Client $c) => [
                'id' => $c->id,
                'name' => $c->full_name,
                'phone' => $c->phone,
                'is_assigned' => $c->assigned_agent_id !== null,
                'link' => ['client_id' => $c->id, 'project_id' => null],
            ])->all();

        return ['total' => (clone $q)->count(), 'items' => $items];
    }

    private function projects(User $user): array
    {
        $q = $this->contributorProjectsQuery($user);

        $items = (clone $q)
            ->with(['client:id,first_name,last_name', 'location:id,name'])
            ->latest()->limit(self::MAX_ITEMS)->get()
            ->map(fn (ClientProject $p) => [
                'id' => $p->id,
                'client' => $p->client?->full_name,
                'location' => $p->location?->name,
                'step' => $p->deriveStep(),
                'is_frozen' => $p->isFrozen(),
                'seat' => $p->created_by === $user->id ? 'creator' : 'viewer',
                'link' => ['client_id' => $p->client_id, 'project_id' => $p->id],
            ])->all();

        return ['total' => (clone $q)->count(), 'items' => $items];
    }

    private function nextActions(User $user): array
    {
        $q = $this->pendingActionsQuery($user);

        $items = (clone $q)
            // Morph-aware nesting, like BuildUpcomingWork: project subjects
            // carry their client so the name resolves without lazy loads.
            ->with(['subject' => fn (MorphTo $m) => $m->morphWith([
                ClientProject::class => ['client:id,first_name,last_name'],
            ])])
            ->orderBy('due_at')->limit(self::MAX_ITEMS)->get()
            ->map(fn (NextAction $a) => [
                'id' => $a->id,
                'type' => $a->type->value,
                'due_at' => $a->due_at,
                'is_overdue' => $a->due_at !== null && $a->due_at->isPast(),
                // Only in-site plans can go back to the dispatch pool.
                'can_pool' => $a->type === NextActionType::InSiteVisit,
                'client' => $this->subjectClient($a->subject),
                'link' => $this->subjectLink($a->subject),
            ])->all();

        return [
            'total' => (clone $q)->count(),
            // Exact, not derived from the capped items: the pool option must
            // appear even when every in-site plan sorts past the item cap.
            'poolable_total' => (clone $q)
                ->where('type', NextActionType::InSiteVisit->value)->count(),
            'items' => $items,
        ];
    }

    private function visits(User $user): array
    {
        $q = $this->openVisitsQuery($user);

        $items = (clone $q)
            ->with([
                'client:id,first_name,last_name',
                'clientProject:id,client_id',
                'clientProject.client:id,first_name,last_name',
                'unit:id,reference,location_id',
                'unit.location:id,name',
            ])
            ->orderBy('scheduled_at')->limit(self::MAX_ITEMS)->get()
            ->map(fn (Visit $v) => [
                'id' => $v->id,
                'type' => $v->type->value,
                'scheduled_at' => $v->scheduled_at,
                'is_overdue' => $v->scheduled_at !== null && $v->scheduled_at->isPast(),
                'client' => ($v->clientProject?->client ?? $v->client)?->full_name,
                'unit' => $v->unit?->reference,
                'location' => $v->unit?->location?->name,
                'link' => ['client_id' => $v->client_id, 'project_id' => $v->client_project_id],
            ])->all();

        return ['total' => (clone $q)->count(), 'items' => $items];
    }

    private function tasks(User $user): array
    {
        $q = $this->openTasksQuery($user);

        $items = (clone $q)->orderBy('due_at')->limit(self::MAX_ITEMS)->get()
            ->map(fn (Task $t) => [
                'id' => $t->id,
                'title' => $t->title,
                'due_at' => $t->due_at,
                'is_overdue' => $t->due_at !== null && $t->due_at->isPast(),
            ])->all();

        return ['total' => (clone $q)->count(), 'items' => $items];
    }

    /** Deep-link parts for a next-action's subject, like BuildOversight. */
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
}
