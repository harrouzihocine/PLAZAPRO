<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Models;

use App\Modules\Inventory\Models\Location;
use App\Modules\Settings\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The cost figures for one development (a `locations` row), unlocking the
 * profitability KPIs. Editable configuration, one row per location. total() is
 * the sum used as the denominator for margin / ROI / break-even.
 */
class ProjectCost extends Model
{
    protected $fillable = [
        'location_id', 'land_cost', 'construction_cost', 'fees', 'notes', 'updated_by',
    ];

    protected $casts = [
        'land_cost' => 'decimal:2',
        'construction_cost' => 'decimal:2',
        'fees' => 'decimal:2',
    ];

    /** Total invested in the development (denominator for margin / ROI). */
    public function total(): string
    {
        return bcadd(bcadd((string) $this->land_cost, (string) $this->construction_cost, 2), (string) $this->fees, 2);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
