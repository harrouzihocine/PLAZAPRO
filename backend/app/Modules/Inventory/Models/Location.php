<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Core\Models\BaseModel;
use App\Modules\Inventory\Enums\GtmPriority;
use App\Modules\Settings\Models\Commune;
use App\Modules\Settings\Models\DynamicListItem;
use App\Modules\Settings\Models\Wilaya;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
        'name', 'code', 'wilaya_id', 'commune_id', 'type_id', 'contract_type_id', 'address',
        'description', 'expected_delivery_date', 'gtm_priority', 'latitude', 'longitude',
        'cover_media_id', 'cover_focus_x', 'cover_focus_y',
        'is_published', 'is_available', 'show_prices', 'show_availability',
        'marketing_tagline', 'marketing_description', 'construction_progress',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'expected_delivery_date' => 'date',
            'gtm_priority' => GtmPriority::class,
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'is_published' => 'boolean',
            'is_available' => 'boolean',
            'show_prices' => 'boolean',
            'show_availability' => 'boolean',
            'marketing_tagline' => 'array',
            'marketing_description' => 'array',
        ]);
    }

    /**
     * Projects visible on the public showcase — the ONLY scope public
     * endpoints may query through (active + explicitly published). NOTE: a
     * project parked off the market (is_available = false) still passes here —
     * the public site keeps showing it, greyed; only internal selectors hide it.
     */
    public function scopePublished($query)
    {
        return $query->active()->where('is_published', true);
    }

    /**
     * Projects a user may pick in a selector: live and for sale. Parked projects
     * (is_available = false) drop out so agents can't shortlist / deal against
     * inventory the promoteur pulled from the market.
     */
    public function scopeSelectable($query)
    {
        return $query->active()->where('is_available', true);
    }

    /**
     * The site's Google Maps deep link (null without coordinates) — THE single
     * backend builder; the frontend twin is googleMapsUrl() in
     * frontend/src/features/inventory/googleMaps.js.
     */
    public function mapsUrl(): ?string
    {
        if ($this->latitude === null || $this->longitude === null) {
            return null;
        }

        return sprintf('https://www.google.com/maps/search/?api=1&query=%s,%s', $this->latitude, $this->longitude);
    }

    public function wilaya(): BelongsTo
    {
        return $this->belongsTo(Wilaya::class);
    }

    public function commune(): BelongsTo
    {
        return $this->belongsTo(Commune::class);
    }

    /** The project type (a `project_types` dynamic-list item: open / closed / semi-closed residence). */
    public function type(): BelongsTo
    {
        return $this->belongsTo(DynamicListItem::class, 'type_id');
    }

    /** The sale contract type (a `contract_types` dynamic-list item). */
    public function contractType(): BelongsTo
    {
        return $this->belongsTo(DynamicListItem::class, 'contract_type_id');
    }

    /**
     * The financing / payment options this project offers buyers — a set of
     * `project_payment_methods` dynamic-list items (bank loan, instalments,
     * cash…). Distinct from how a payment is settled (`payment_methods`).
     */
    public function paymentMethods(): BelongsToMany
    {
        return $this->belongsToMany(
            DynamicListItem::class,
            'location_payment_methods',
            'location_id',
            'dynamic_list_item_id',
        )->withTimestamps();
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

    /** The chosen cover picture (a row in `media`), shown on the card and hero. */
    public function coverMedia(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'cover_media_id');
    }
}
