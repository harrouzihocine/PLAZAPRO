<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Core\Models\BaseModel;
use App\Modules\Inventory\Enums\GtmPriority;
use App\Modules\Settings\Models\Commune;
use App\Modules\Settings\Models\Wilaya;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A real-estate project / building / site. Holds units and boxes and carries
 * polymorphic media. `wilaya_id`/`commune_id` point at the geographic tables.
 */
class Location extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'name', 'code', 'wilaya_id', 'commune_id', 'address', 'description',
        'expected_delivery_date', 'gtm_priority', 'latitude', 'longitude',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'expected_delivery_date' => 'date',
            'gtm_priority' => GtmPriority::class,
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ]);
    }

    public function wilaya(): BelongsTo
    {
        return $this->belongsTo(Wilaya::class);
    }

    public function commune(): BelongsTo
    {
        return $this->belongsTo(Commune::class);
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
