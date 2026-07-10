<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Models;

use App\Core\Models\BaseModel;
use App\Modules\Pipeline\Enums\TaskCategory;
use App\Modules\Pipeline\Enums\TaskOutcome;
use App\Modules\Pipeline\Enums\TaskPriority;
use App\Modules\Pipeline\Enums\TaskState;
use App\Modules\Settings\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A general to-do assigned to a user, optionally about a client/unit/deal. The
 * dedicated tasks page lands in Phase 5; this is the foundation.
 */
class Task extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'title', 'description', 'category', 'assigned_to', 'created_by',
        'subject_type', 'subject_id', 'due_at', 'priority', 'state',
        'repeat_every_hours', 'completed_at', 'completed_by',
        'completion_outcome', 'completion_summary', 'completion_difficulties',
        'time_spent_minutes',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
            'category' => TaskCategory::class,
            'priority' => TaskPriority::class,
            'state' => TaskState::class,
            'completion_outcome' => TaskOutcome::class,
        ]);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(Reminder::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('state', TaskState::Open->value);
    }
}
