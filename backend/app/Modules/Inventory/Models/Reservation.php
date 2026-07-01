<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Core\Models\BaseModel;
use App\Modules\Inventory\Enums\HoldStatus;
use App\Modules\Settings\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A 48-hour reservation hold on a unit. Flips to expired by the scheduled sweeper
 * (ExpireReservationHolds) if not converted or released first.
 */
class Reservation extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'unit_id', 'client_project_id', 'held_by', 'held_at', 'expires_at', 'hold_status',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'held_at' => 'datetime',
            'expires_at' => 'datetime',
            'hold_status' => HoldStatus::class,
        ]);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function holder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'held_by');
    }

    public function scopeActiveHold(Builder $query): Builder
    {
        return $query->where('hold_status', HoldStatus::Active->value);
    }
}
