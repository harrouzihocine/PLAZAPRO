<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Core\Models\BaseModel;
use App\Modules\Inventory\Enums\HoldStatus;
use App\Modules\Inventory\Enums\SaleStatus;
use App\Modules\Settings\Models\DynamicListItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * An apartment / lot inside a location. price and sale_status corrections go
 * through HasVersions::supersedeWith (cancel-and-duplicate); ordinary spec edits
 * and reservation lifecycle transitions are plain updates.
 */
class Unit extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'location_id', 'reference', 'type_id', 'floor_id', 'area_sqm', 'rooms',
        'price', 'sale_status', 'block', 'stack_floor', 'position',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'price' => 'decimal:2',
            'area_sqm' => 'decimal:2',
            'sale_status' => SaleStatus::class,
        ]);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(DynamicListItem::class, 'type_id');
    }

    public function floor(): BelongsTo
    {
        return $this->belongsTo(DynamicListItem::class, 'floor_id');
    }

    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'mediable');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    /** The current active hold, if any (at most one per unit at a time). */
    public function activeReservation(): HasOne
    {
        return $this->hasOne(Reservation::class)
            ->where('hold_status', HoldStatus::Active->value)
            ->latest('id');
    }

    /** Units currently offered for sale. */
    public function scopeSaleStatus(Builder $query, string $status): Builder
    {
        return $query->where('sale_status', $status);
    }
}
