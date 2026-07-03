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
use App\Modules\Pipeline\Models\Call;
use App\Modules\Pipeline\Models\Visit;
use App\Modules\Settings\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
        'client_id', 'location_id', 'unit_id', 'stage', 'total_price', 'closed_to_desire_at',
    ];

    /**
     * Visibility rule (projects.view_all): without the grant a user sees only
     * the projects they created plus the ones they were added to as a viewer
     * (and not since hidden).
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
                ->whereNull('client_project_viewers.hidden_at')));
    }

    /** Single-model version of scopeVisibleTo — guards project-scoped reads. */
    public function isVisibleTo(User $user): bool
    {
        return $user->can('projects.view_all')
            || $this->created_by === $user->id
            || $this->viewers()
                ->whereKey($user->id)
                ->whereNull('client_project_viewers.hidden_at')
                ->exists();
    }

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'stage' => ClientProjectStage::class,
            'total_price' => 'decimal:2',
            'closed_to_desire_at' => 'datetime',
        ]);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
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

    /** The deals opened on this project (one active at a time — CreateDeal). */
    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }

    /** THE open (reserved) deal, when one exists. */
    public function activeDeal(): HasOne
    {
        return $this->hasOne(Deal::class)
            ->where('deals.status', 'active')
            ->where('state', DealState::Reserved->value)
            ->latest('id');
    }

    public function calls(): HasMany
    {
        return $this->hasMany(Call::class);
    }

    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
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
