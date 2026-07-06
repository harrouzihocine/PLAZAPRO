<?php

declare(strict_types=1);

namespace App\Modules\Clients\Models;

use App\Core\Models\BaseModel;
use App\Modules\Clients\Enums\DealState;
use App\Modules\Pipeline\Models\Call;
use App\Modules\Pipeline\Models\Visit;
use App\Modules\Settings\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The reservation-stage commitment on a client project: the properties the client
 * actually wants, auto-reserved together. A project may carry SEVERAL deals at
 * once — typically one per apartment committed at an in-site visit.
 * Provenance: visit_id / call_id is the interaction log the deal came from —
 * one is required unless the creator holds deals.direct. Each apartment item
 * closes won / lost on its own; the deal closes when every item is resolved
 * (won when at least one apartment sold, lost when the client passed on all).
 */
class Deal extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'client_project_id', 'visit_id', 'call_id', 'state', 'total_price', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'state' => DealState::class,
            'total_price' => 'decimal:2',
        ]);
    }

    public function clientProject(): BelongsTo
    {
        return $this->belongsTo(ClientProject::class);
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(DealItem::class);
    }

    /** The reserved apartments / locals on the deal. */
    public function unitItems(): HasMany
    {
        return $this->hasMany(DealItem::class)->whereNotNull('unit_id');
    }

    /** The boxes allocated alongside the units. */
    public function boxItems(): HasMany
    {
        return $this->hasMany(DealItem::class)->whereNotNull('box_id');
    }
}
