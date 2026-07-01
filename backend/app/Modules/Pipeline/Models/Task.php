<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Models;

use App\Core\Models\BaseModel;
use App\Modules\Pipeline\Enums\TaskPriority;
use App\Modules\Pipeline\Enums\TaskState;
use App\Modules\Settings\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A general to-do assigned to a user, optionally about a client/unit/deal. The
 * dedicated tasks page lands in Phase 5; this is the foundation.
 */
class Task extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'title', 'description', 'assigned_to', 'subject_type', 'subject_id',
        'due_at', 'priority', 'state',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'due_at' => 'datetime',
            'priority' => TaskPriority::class,
            'state' => TaskState::class,
        ]);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('state', TaskState::Open->value);
    }
}
