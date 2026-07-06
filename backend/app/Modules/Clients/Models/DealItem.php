<?php

declare(strict_types=1);

namespace App\Modules\Clients\Models;

use App\Core\Models\BaseModel;
use App\Modules\Clients\Enums\DealState;
use App\Modules\Inventory\Models\Box;
use App\Modules\Inventory\Models\Unit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One property on a deal — either a unit (apartment / local) or a box
 * riding with it (parent_item_id = the apartment's item). Each APARTMENT item
 * has its own lifecycle: open → won (agreed_price set, payments start) or
 * lost (released back); its box items follow it. `box_linked` marks a box this
 * deal linked to the apartment — reverted when the item is lost / removed.
 * Cancelled (not deleted) when removed from the deal, so history stays.
 */
class DealItem extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'deal_id', 'unit_id', 'box_id', 'parent_item_id',
        'state', 'agreed_price', 'closed_at', 'box_linked', 'credits',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'state' => DealState::class,
            'agreed_price' => 'decimal:2',
            'closed_at' => 'datetime',
            'box_linked' => 'boolean',
            // { sale: [user ids], insite: [user ids], other: [user ids] }
            'credits' => 'array',
        ]);
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function box(): BelongsTo
    {
        return $this->belongsTo(Box::class);
    }

    /** The apartment item a box item rides with. */
    public function parentItem(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_item_id');
    }

    /** An apartment item's boxes. */
    public function boxItems(): HasMany
    {
        return $this->hasMany(self::class, 'parent_item_id');
    }
}
