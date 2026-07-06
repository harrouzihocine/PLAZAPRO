<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Models;

use App\Core\Models\BaseModel;
use App\Modules\Pipeline\Enums\NextActionState;
use App\Modules\Pipeline\Enums\NextActionType;
use App\Modules\Settings\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * The enforced next step for a client/project. `subject` is the client/project it
 * belongs to; `source` is the call/visit that created it. A subject has at most one
 * open `pending` action at a time (kept true by CreateNextAction).
 */
class NextAction extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'subject_type', 'subject_id', 'source_type', 'source_id',
        'type', 'due_at', 'assigned_to', 'target_unit_ids', 'state', 'completed_at',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'type' => NextActionType::class,
            'state' => NextActionState::class,
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
            // The specific apartment(s) an in-site plan targets (else the whole shortlist).
            'target_unit_ids' => 'array',
        ]);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(Reminder::class);
    }

    /** Visits materialized from this plan (SyncVisitFromNextAction). */
    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('state', NextActionState::Pending->value);
    }

    /** Pending actions whose due date has passed. */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('state', NextActionState::Pending->value)
            ->where('due_at', '<=', now());
    }
}
