<?php

declare(strict_types=1);

namespace App\Modules\Clients\Models;

use App\Core\Models\BaseModel;
use App\Modules\Inventory\Models\Box;
use App\Modules\Inventory\Models\Unit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One reserved property on a deal — either a unit (apartment / local) or a box
 * allocated alongside it. Exactly one of unit_id / box_id is set. Cancelled (not
 * deleted) when removed from the deal, so the reservation history stays.
 */
class DealItem extends BaseModel
{
    use HasFactory;

    protected $fillable = ['deal_id', 'unit_id', 'box_id'];

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
}
