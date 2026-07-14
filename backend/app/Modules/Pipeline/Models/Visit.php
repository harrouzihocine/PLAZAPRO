<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Models;

use App\Core\Models\BaseModel;
use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Clients\Models\Deal;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Pipeline\Enums\VisitType;
use App\Modules\Settings\Models\DynamicListItem;
use App\Modules\Settings\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
        'next_action_id', 'scheduled_at', 'completed_at', 'visited_at', 'outcome_id', 'notes', 'checklist', 'objections',
        'assigned_at', 'accepted_at', 'en_route_at', 'arrived_at', 'departed_at',
        'declined_at', 'decline_reason', 'acceptance_alerted_at', 'late_alerted_at',
        'offroute_alerted_at',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'type' => VisitType::class,
            'checklist' => 'array',
            'objections' => 'array',
            'scheduled_at' => 'datetime',
            'completed_at' => 'datetime',
            'visited_at' => 'datetime',
            'assigned_at' => 'datetime',
            'accepted_at' => 'datetime',
            'en_route_at' => 'datetime',
            'arrived_at' => 'datetime',
            'departed_at' => 'datetime',
            'declined_at' => 'datetime',
            'acceptance_alerted_at' => 'datetime',
            'late_alerted_at' => 'datetime',
            'offroute_alerted_at' => 'datetime',
        ]);
    }

    protected static function booted(): void
    {
        // The dispatch lifecycle belongs to the CURRENT agent: whoever the
        // visit lands on, the accept-SLA clock starts now and the previous
        // agent's accept/en-route/arrival stamps (and sent alerts) are void.
        // Centralized here so every assignment path — schedule, materialize,
        // board drag, /assign — behaves identically.
        static::creating(function (Visit $visit) {
            if ($visit->agent_id !== null && $visit->assigned_at === null) {
                $visit->assigned_at = now();
            }
        });

        static::updating(function (Visit $visit) {
            if ($visit->isDirty('agent_id') && $visit->agent_id !== null && $visit->completed_at === null) {
                $visit->assigned_at = now();
                $visit->accepted_at = null;
                $visit->en_route_at = null;
                $visit->arrived_at = null;
                $visit->departed_at = null;
                $visit->acceptance_alerted_at = null;
                $visit->late_alerted_at = null;
                $visit->offroute_alerted_at = null;
            }
        });
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

    /** The deal this visit opened (provenance), if any — at most one active. */
    public function deal(): HasOne
    {
        return $this->hasOne(Deal::class, 'visit_id')->active();
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }

    /**
     * Where this visit stands on the dispatch lifecycle, derived from which
     * stamps exist (single source of truth — never stored as a column):
     * assigned → accepted → en_route → arrived → done.
     */
    public function dispatchStatus(): string
    {
        return match (true) {
            $this->completed_at !== null => 'done',
            $this->arrived_at !== null => 'arrived',
            $this->en_route_at !== null => 'en_route',
            $this->accepted_at !== null => 'accepted',
            default => 'assigned',
        };
    }
}
