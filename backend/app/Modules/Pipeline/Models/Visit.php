<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Models;

use App\Core\Models\BaseModel;
use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Pipeline\Enums\VisitType;
use App\Modules\Settings\Models\DynamicListItem;
use App\Modules\Settings\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * An office or apartment visit. apartment visits require a unit; the agent must be
 * a user whose role is_agent. Completing a visit leaves a next action.
 */
class Visit extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'client_id', 'client_project_id', 'type', 'unit_id', 'agent_id',
        'next_action_id', 'scheduled_at', 'completed_at', 'outcome_id', 'notes', 'checklist',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'type' => VisitType::class,
            'checklist' => 'array',
            'scheduled_at' => 'datetime',
            'completed_at' => 'datetime',
        ]);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function clientProject(): BelongsTo
    {
        return $this->belongsTo(ClientProject::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function outcome(): BelongsTo
    {
        return $this->belongsTo(DynamicListItem::class, 'outcome_id');
    }

    public function nextActions(): MorphMany
    {
        return $this->morphMany(NextAction::class, 'source');
    }

    /** The next action (plan) this visit was materialized from, if any. */
    public function nextAction(): BelongsTo
    {
        return $this->belongsTo(NextAction::class);
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }
}
