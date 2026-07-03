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
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
