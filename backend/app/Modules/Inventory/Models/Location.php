<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Core\Models\BaseModel;
use App\Modules\Settings\Models\DynamicListItem;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A real-estate project / building / site. Holds units and boxes and carries
 * polymorphic media. `area_id` points at the `areas` dynamic list.
 */
class Location extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'name', 'code', 'area_id', 'address', 'description', 'latitude', 'longitude',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ]);
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(DynamicListItem::class, 'area_id');
    }

    public function units(): HasMany
    {
        return $this->hasMany(Unit::class);
    }

    public function boxes(): HasMany
    {
        return $this->hasMany(Box::class);
    }

    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'mediable');
    }
}
