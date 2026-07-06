<?php

declare(strict_types=1);

namespace App\Modules\Clients\Models;

use App\Core\Models\BaseModel;
use App\Modules\Inventory\Models\Location;
use App\Modules\Settings\Models\Commune;
use App\Modules\Settings\Models\DynamicListItem;
use App\Modules\Settings\Models\Wilaya;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * What a client is looking for. One client-level desire per client (upserted);
 * the nullable client_project_id leaves room for per-deal desires later. Matched
 * to inventory by MatchDesireToInventory. The structured fields mirror the unit
 * form (type / floor / area / budget) plus the preferred sites (locations pivot);
 * notes are required — the story behind the numbers.
 */
class Desire extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'client_id', 'client_project_id', 'wilaya_id', 'commune_id', 'type_id',
        'room_number_id', 'contract_type_id', 'floor_id', 'floor_pref',
        'area_min', 'area_max', 'rooms_min', 'budget_min', 'budget_max', 'notes',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'budget_min' => 'decimal:2',
            'budget_max' => 'decimal:2',
            'area_min' => 'decimal:2',
            'area_max' => 'decimal:2',
            'rooms_min' => 'integer',
        ]);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function wilaya(): BelongsTo
    {
        return $this->belongsTo(Wilaya::class);
    }

    public function commune(): BelongsTo
    {
        return $this->belongsTo(Commune::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(DynamicListItem::class, 'type_id');
    }

    /** Preferred number of rooms (a `room_numbers` item), e.g. F2 / F3. */
    public function roomNumber(): BelongsTo
    {
        return $this->belongsTo(DynamicListItem::class, 'room_number_id');
    }

    /** Preferred sale contract (a `contract_types` item), e.g. VEFA / turnkey. */
    public function contractType(): BelongsTo
    {
        return $this->belongsTo(DynamicListItem::class, 'contract_type_id');
    }

    public function floor(): BelongsTo
    {
        return $this->belongsTo(DynamicListItem::class, 'floor_id');
    }

    /** The sites (projects) the client would like to buy into. */
    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class, 'desire_locations')->withTimestamps();
    }
}
