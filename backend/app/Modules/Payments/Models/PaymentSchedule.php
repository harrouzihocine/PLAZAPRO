<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Core\Models\BaseModel;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Payments\Enums\ScheduleState;
use App\Modules\Payments\Support\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One instalment in an apartment's payment plan (unit_id scopes the plan to a
 * won apartment — each apartment on a deal is tracked alone; null only on
 * legacy project-level plans). `state`/`paid_amount` are derived by the
 * AllocateVersement Action and the overdue sweep — never client-set.
 */
class PaymentSchedule extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'client_project_id', 'unit_id', 'installment_no', 'due_date', 'amount', 'state', 'paid_amount',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'state' => ScheduleState::class,
            'installment_no' => 'integer',
            'due_date' => 'date',
            'amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
        ]);
    }

    public function clientProject(): BelongsTo
    {
        return $this->belongsTo(ClientProject::class);
    }

    /** The won apartment this instalment pays for. */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** Recorded versements allocated to this instalment (active only via scope). */
    public function versements(): HasMany
    {
        return $this->hasMany(Versement::class, 'schedule_item_id');
    }

    /**
     * The single source of truth for an instalment's payment state, derived from
     * how much is allocated against the planned amount and whether it is past due.
     * Used by AllocateVersement (on record/correct) and the overdue sweep so both
     * stay consistent — the frontend never computes this.
     */
    public function deriveState(string $paidAmount): ScheduleState
    {
        if (Money::compare($paidAmount, (string) $this->amount) >= 0) {
            return ScheduleState::Paid;
        }

        $pastDue = $this->due_date !== null
            && $this->due_date->startOfDay()->lt(now()->startOfDay());

        if (Money::isPositive($paidAmount)) {
            return $pastDue ? ScheduleState::Overdue : ScheduleState::Partial;
        }

        return $pastDue ? ScheduleState::Overdue : ScheduleState::Pending;
    }
}
