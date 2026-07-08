<?php

declare(strict_types=1);

namespace App\Modules\Settings\Models;

use App\Core\Models\BaseModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One option within a dynamic list. Referenced by `id` from other tables (e.g.
 * clients.source_id), so adding an option needs no migration. `is_active` hides
 * an option from dropdowns without deleting it (the "deactivate, don't remove"
 * rule); the base-model `status` remains the true no-delete tombstone.
 */
class DynamicListItem extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'dynamic_list_id',
        'label',
        'label_translations',
        'value',
        'sort_order',
        'parent_id',
        'is_active',
        'meta',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'meta' => 'array',
            'label_translations' => 'array',
        ]);
    }

    /** The label in the given (or current) locale, falling back to the base label. */
    public function localizedLabel(?string $locale = null): string
    {
        return $this->label_translations[$locale ?? app()->getLocale()] ?? $this->label;
    }

    public function list(): BelongsTo
    {
        return $this->belongsTo(DynamicList::class, 'dynamic_list_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
