<?php

declare(strict_types=1);

namespace App\Modules\Clients\Models;

use App\Core\Models\BaseModel;
use App\Modules\Clients\Enums\ClientProjectStage;
use App\Modules\Clients\Enums\DealState;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Payments\Models\PaymentSchedule;
use App\Modules\Payments\Models\Versement;
use App\Modules\Payments\Support\Money;
use App\Modules\Pipeline\Enums\VisitType;
use App\Modules\Pipeline\Models\Call;
use App\Modules\Pipeline\Models\NextAction;
use App\Modules\Pipeline\Models\Visit;
use App\Modules\Settings\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;

/**
 * A project engagement the client is pursuing: it owns the interaction logs
 * (calls / visits), the property shortlist and the deal(s). Stage moves through
 * the pipeline (ClientProjectStage), constrained to legal transitions on the
 * manual endpoint; the deal lifecycle moves it automatically. Cancelled, never
 * deleted (BaseModel). closed_to_desire_at marks a project shifted back to the
 * desire list (archived, waiting for matching inventory).
 */
class ClientProject extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'client_id', 'continued_from_project_id', 'hidden_from_owner',
        'location_id', 'unit_id', 'stage', 'total_price', 'closed_to_desire_at',
        'frozen_at', 'frozen_by',
    ];

    /**
     * Visibility rule (projects.view_all): without the grant a user sees only
     * the projects they created, the ones they were added to as a viewer (and not
     * since hidden), plus every project of a client they OWN — its creator or its
     * assigned agent. A client's own agent is never locked out of that client's
     * projects, even a project a colleague opened on it.
     *
     * A dispatched FIELD AGENT also sees the project holding their (live)
     * in-site visit — their working remit: the dispatch notification deep-links
     * here, and they must reach the project to conduct and complete that visit
     * (and keep seeing their completed log afterwards). Reassigning the visit
     * moves agent_id, so the replaced agent drops out on their own.
     *
     * Exception: a hidden_from_owner project (a duplicate-resolution "separate
     * project") is siloed from the client owner — the owner-branch does NOT reach
     * it. Its own creator still sees it (created_by), and view_all still sees all.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->can('projects.view_all')) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->where('created_by', $user->id)
            ->orWhereHas('viewers', fn (Builder $v) => $v
                ->whereKey($user->id)
                ->whereNull('client_project_viewers.hidden_at'))
            ->orWhereHas('visits', fn (Builder $v) => $v
                ->active()
                ->where('type', VisitType::InSite->value)
                ->where('agent_id', $user->id))
            ->orWhere(fn (Builder $owner) => $owner
                ->where('hidden_from_owner', false)
                ->whereHas('client', fn (Builder $c) => $c
                    ->where('created_by', $user->id)
                    ->orWhere('assigned_agent_id', $user->id))));
    }

    /**
     * THE contributor rule, in one place: the creator plus the non-hidden
     * viewers. Chat participants, assignment notifications and the upcoming-work
     * digest all derive membership from this — never re-encode it.
     *
     * @return Collection<int, int>
     */
    public function contributorIds(): Collection
    {
        return collect($this->created_by !== null ? [$this->created_by] : [])
            ->merge($this->viewers()->whereNull('client_project_viewers.hidden_at')->pluck('users.id'))
            ->unique()
            ->values();
    }

    /** Single-model version of scopeVisibleTo — guards project-scoped reads. */
    public function isVisibleTo(User $user): bool
    {
        return $user->can('projects.view_all')
            || $this->isContributor($user)
            || (! $this->hidden_from_owner && $this->isClientOwner($user))
            || $this->isDispatchedFieldAgent($user);
    }

    /**
     * The client's own agent: its creator or its assigned agent. Such a user is
     * never locked out of the client's projects (scopeVisibleTo / isVisibleTo) —
     * but this is deliberately NOT part of isContributor / contributorIds, so it
     * does not change who joins the project chat or gets its notifications, nor
     * does it reveal the collaborator list to a name-only owner.
     */
    public function isClientOwner(User $user): bool
    {
        return $this->client !== null
            && ($this->client->created_by === $user->id
                || $this->client->assigned_agent_id === $user->id);
    }

    /**
     * A member OF this project: its creator or a non-hidden viewer. Unlike
     * isVisibleTo this ignores projects.view_all — an overseer may READ the
     * project (view-all) without being one of the people actually working it.
     */
    public function isContributor(User $user): bool
    {
        return $this->created_by === $user->id
            || $this->viewers()
                ->whereKey($user->id)
                ->whereNull('client_project_viewers.hidden_at')
                ->exists();
    }

    /**
     * A user tied to this project ONLY by a field-agent dispatch: they hold one
     * of its (live) in-site visits, but are neither a contributor (creator /
     * viewer) nor the client's own agent. Their remit is that in-site visit —
     * they may not log the project's calls or complete its office visits (the
     * FormRequests enforce it). A contributor, the client owner, or a visit
     * administrator (visits.assign, layered on by the caller) is never treated
     * as dispatch-only. Mirrors how the chat grant refuses to downgrade a real
     * contributor who happens to be the dispatched agent.
     */
    public function isDispatchOnlyAgent(User $user): bool
    {
        if ($this->isContributor($user) || $this->isClientOwner($user)) {
            return false;
        }

        return $this->isDispatchedFieldAgent($user);
    }

    /**
     * The user holds one of this project's (live) in-site visits — the dispatch
     * tie itself, regardless of any other role they may also have here. This is
     * what lets a field agent SEE the project (isVisibleTo / scopeVisibleTo and
     * Client::scopeVisibleTo mirror it); isDispatchOnlyAgent layers on "…and
     * nothing more" to RESTRICT what they may do. A cancelled visit never ties;
     * a completed one still does — the agent keeps their log's story.
     */
    public function isDispatchedFieldAgent(User $user): bool
    {
        return $this->visits()->active()
            ->where('type', VisitType::InSite->value)
            ->where('agent_id', $user->id)
            ->exists();
    }

    /**
     * Whether this project's collaborator identity — who opened it, who can see
     * it, and its chat — may be revealed to a user. Kept OFF name-only lookers
     * (no clients.view_details) so a colleague's client cannot be poached: only
     * the project's own members, the client's own agent, or someone trusted with
     * the client's details, see who is behind it. Mirrors ClientResource, which
     * hides the client's contact details and ownership from the very same
     * population.
     *
     * The client OWNER (creator / assigned agent) is included even without
     * view_details: it is their own client, so seeing who opened each project
     * and who worked it — the logs' and history's authors — is not poaching.
     */
    public function collaboratorsVisibleTo(User $user): bool
    {
        return $this->isContributor($user)
            || $this->isClientOwner($user)
            || ($this->client?->isDetailVisibleTo($user) ?? false);
    }

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'stage' => ClientProjectStage::class,
            'total_price' => 'decimal:2',
            'closed_to_desire_at' => 'datetime',
            'frozen_at' => 'datetime',
            'hidden_from_owner' => 'boolean',
        ]);
    }

    /** The user who froze this project (projects.freeze). */
    public function freezer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'frozen_by');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * The earlier project this one continues (duplicate-resolution "separate
     * project" outcome). Oversight-only: surfaced to projects.view_all holders as
     * a "continuation of an earlier engagement" marker — never to the finder, who
     * must not learn the original exists.
     */
    public function continuedFrom(): BelongsTo
    {
        return $this->belongsTo(ClientProject::class, 'continued_from_project_id');
    }

    /** The user who opened this project. */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The users this project was shared with ("who can see this project").
     * Rows are hidden (pivot hidden_at), never deleted — re-adding un-hides.
     */
    public function viewers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'client_project_viewers')
            ->withPivot(['added_by', 'hidden_at'])
            ->withTimestamps();
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** The instalment plan for this deal (Phase 4). */
    public function paymentSchedules(): HasMany
    {
        return $this->hasMany(PaymentSchedule::class);
    }

    /** Recorded instalment payments against this deal (Phase 4). */
    public function versements(): HasMany
    {
        return $this->hasMany(Versement::class);
    }

    /** The properties (units/boxes) shortlisted for this deal at the office visit. */
    public function shortlistItems(): HasMany
    {
        return $this->hasMany(ShortlistItem::class);
    }

    /** The deals opened on this project (several may be open at once). */
    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }

    /** The latest open deal, when one exists. */
    public function activeDeal(): HasOne
    {
        return $this->hasOne(Deal::class)
            ->where('deals.status', 'active')
            ->where('state', DealState::Open->value)
            ->latest('id');
    }

    /**
     * The agreed price a payment plan must reconcile to — per apartment. A won
     * deal item carries the apartment's own agreed price (it covers its boxes);
     * legacy wins (closed before per-unit tracking) fall back to the project's
     * stamped unit + total. Null when the unit was never won here.
     */
    /**
     * Re-derive the won stamp from every won apartment across the project's
     * active deals (a project may carry several). Returns true when at least
     * one won apartment remains — the project is (still) won and stamped with
     * the first sold unit + the aggregate agreed total. False leaves the
     * caller to decide where the project steps back to.
     */
    public function restampFromWonDeals(): bool
    {
        $won = DealItem::query()->active()
            ->whereNotNull('unit_id')
            ->where('state', DealState::Won->value)
            ->whereHas('deal', fn (Builder $q) => $q
                ->where('client_project_id', $this->id)
                ->where('status', 'active'))
            ->orderBy('id')
            ->get();

        if ($won->isEmpty()) {
            return false;
        }

        $this->update([
            'stage' => ClientProjectStage::Won->value,
            'unit_id' => $won->first()->unit_id,
            'total_price' => $won->reduce(
                fn (string $sum, DealItem $item) => Money::add($sum, (string) $item->agreed_price),
                '0.00',
            ),
        ]);

        return true;
    }

    public function agreedPriceForUnit(?int $unitId): ?string
    {
        if ($unitId === null) {
            return $this->total_price !== null ? (string) $this->total_price : null;
        }

        $item = DealItem::query()->active()
            ->where('unit_id', $unitId)
            ->where('state', DealState::Won->value)
            ->whereNotNull('agreed_price')
            ->whereHas('deal', fn (Builder $q) => $q
                ->where('client_project_id', $this->id)
                ->where('status', 'active'))
            ->latest('id')
            ->first();

        if ($item !== null) {
            return (string) $item->agreed_price;
        }

        return $this->unit_id !== null && (int) $this->unit_id === $unitId && $this->total_price !== null
            ? (string) $this->total_price
            : null;
    }

    public function calls(): HasMany
    {
        return $this->hasMany(Call::class);
    }

    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }

    /** The next-action plans whose subject is this project (for the stuck monitor). */
    public function nextActions(): MorphMany
    {
        return $this->morphMany(NextAction::class, 'subject');
    }

    /**
     * True when nothing was ever logged inside the project — no calls, visits,
     * shortlist, deals or payments, in ANY lifecycle state (cancelled history
     * counts as content). Only an empty project may be removed; a filled one
     * must be archived so its story is kept.
     */
    public function isEmpty(): bool
    {
        return ! $this->calls()->exists()
            && ! $this->visits()->exists()
            && ! $this->shortlistItems()->exists()
            && ! $this->deals()->exists()
            && ! $this->paymentSchedules()->exists()
            && ! $this->versements()->exists();
    }

    /**
     * A frozen project is closed to NEW activity (calls, plans, visits, deals,
     * chat): it has been archived, cancelled, or EXPLICITLY frozen (frozen_at,
     * a deliberate projects.freeze act). Payments and documents still flow.
     * Won no longer freezes by itself — a won project can keep living (the
     * client may buy another apartment on it); freeze it to close it down.
     */
    public function isFrozen(): bool
    {
        return $this->isArchived()
            || $this->isCancelled()
            || $this->frozen_at !== null;
    }

    /**
     * Where the project stands, as one badge-able step. Derived — never stored —
     * from the lifecycle state, the open deal and the interaction logs.
     */
    public function deriveStep(): string
    {
        if ($this->isArchived()) {
            return $this->closed_to_desire_at !== null ? 'desire' : 'archived';
        }

        if ($this->isCancelled()) {
            return 'removed';
        }

        return match (true) {
            $this->stage === ClientProjectStage::Won => 'won',
            $this->stage === ClientProjectStage::Lost => 'lost',
            $this->activeDeal()->exists() => 'deal',
            $this->visits()->active()->whereNull('completed_at')
                ->where('type', 'in_site')->exists() => 'in_site_visit',
            $this->visits()->active()->whereNull('completed_at')
                ->where('type', 'office')->exists() => 'office_visit',
            $this->shortlistItems()->active()->exists()
                || $this->calls()->active()->exists() => 'qualifying',
            default => 'new',
        };
    }
}
