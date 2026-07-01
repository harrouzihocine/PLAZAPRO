<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Core\Models\BaseModel;
use App\Modules\Inventory\Enums\SaleStatus;
use App\Modules\Settings\Models\DynamicListItem;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A parking or storage box inside a location, optionally attached to a unit.
 */
class Box extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'location_id', 'reference', 'type_id', 'price', 'sale_status', 'unit_id',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'price' => 'decimal:2',
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

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
