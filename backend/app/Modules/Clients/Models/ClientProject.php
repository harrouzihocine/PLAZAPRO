<?php

declare(strict_types=1);

namespace App\Modules\Clients\Models;

use App\Core\Models\BaseModel;
use App\Modules\Clients\Enums\ClientProjectStage;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Payments\Models\PaymentSchedule;
use App\Modules\Payments\Models\Versement;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A deal / opportunity a client is pursuing. Stage moves through the pipeline
 * (ClientProjectStage), constrained to legal transitions. Cancelled, never
 * deleted (BaseModel).
 */
class ClientProject extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'client_id', 'location_id', 'unit_id', 'stage', 'total_price',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'stage' => ClientProjectStage::class,
            'total_price' => 'decimal:2',
        ]);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** The instalment plan for this deal (Phase 4). */
    public function paymentSchedules(): HasMany
    {
        return $this->hasMany(PaymentSchedule::class);
    }

    /** Recorded instalment payments against this deal (Phase 4). */
    public function versements(): HasMany
    {
        return $this->hasMany(Versement::class);
    }
}
