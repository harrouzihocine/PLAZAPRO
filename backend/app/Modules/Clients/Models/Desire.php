<?php

declare(strict_types=1);

namespace App\Modules\Clients\Models;

use App\Core\Models\BaseModel;
use App\Modules\Settings\Models\DynamicListItem;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a client is looking for. One client-level desire per client (upserted);
 * the nullable client_project_id leaves room for per-deal desires later. Matched
 * to inventory by MatchDesireToInventory.
 */
class Desire extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'client_id', 'client_project_id', 'area_id', 'type_id',
        'floor_pref', 'budget_min', 'budget_max', 'notes',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'budget_min' => 'decimal:2',
            'budget_max' => 'decimal:2',
        ]);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(DynamicListItem::class, 'area_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(DynamicListItem::class, 'type_id');
    }
}
