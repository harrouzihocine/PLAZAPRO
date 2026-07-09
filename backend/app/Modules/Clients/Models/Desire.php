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
 * to inventory by MatchDesireToInventory. Every structured criterion is
 * multi-valued ("F2 OR F3", "Hydra OR Kouba"): the dynamic-list criteria share
 * the desire_list_items pivot (a `field` discriminator per criterion), wilayas /
 * communes / preferred sites have their own pivots. No rows = no preference.
 * Notes are required — the story behind the numbers.
 */
class Desire extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'client_id', 'client_project_id', 'floor_pref',
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

    public function wilayas(): BelongsToMany
    {
        return $this->belongsToMany(Wilaya::class, 'desire_wilayas')->withTimestamps();
    }

    public function communes(): BelongsToMany
    {
        return $this->belongsToMany(Commune::class, 'desire_communes')->withTimestamps();
    }

    /** Acceptable project types (`project_types` items). */
    public function types(): BelongsToMany
    {
        return $this->listItems('type');
    }

    /** Acceptable numbers of rooms (`room_numbers` items), e.g. F2 / F3. */
    public function roomNumbers(): BelongsToMany
    {
        return $this->listItems('room_number');
    }

    /** Acceptable sale contracts (`contract_types` items), e.g. VEFA / turnkey. */
    public function contractTypes(): BelongsToMany
    {
        return $this->listItems('contract_type');
    }

    /** Acceptable floors (`floors` items). */
    public function floors(): BelongsToMany
    {
        return $this->listItems('floor');
    }

    /** The sites (projects) the client would like to buy into. */
    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class, 'desire_locations')->withTimestamps();
    }

    /**
     * One dynamic-list criterion's slice of the shared desire_list_items pivot.
     * withPivotValue both scopes reads to the criterion and stamps `field` on
     * attach/sync, so callers just sync ids.
     */
    private function listItems(string $field): BelongsToMany
    {
        return $this->belongsToMany(DynamicListItem::class, 'desire_list_items', 'desire_id', 'item_id')
            ->withPivotValue('field', $field)
            ->withTimestamps();
    }
}
