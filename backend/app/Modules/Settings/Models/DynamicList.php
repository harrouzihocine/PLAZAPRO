<?php

declare(strict_types=1);

namespace App\Modules\Settings\Models;

use App\Core\Models\BaseModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A reusable dropdown list keyed by a stable machine `key` (payment_methods,
 * areas, sources, ...). System lists (is_system) are seeded and protected from
 * rename/removal by admins; their `key` is never editable.
 */
class DynamicList extends BaseModel
{
    use HasFactory;

    protected $fillable = ['key', 'name', 'description', 'is_system'];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'is_system' => 'boolean',
        ]);
    }

    /** Bind route {key} by the machine key, not the numeric id. */
    public function getRouteKeyName(): string
    {
        return 'key';
    }

    public function items(): HasMany
    {
        return $this->hasMany(DynamicListItem::class);
    }

    /** Active items in display order — exactly what dropdowns consume. */
    public function activeItems(): HasMany
    {
        return $this->items()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id');
    }
}
