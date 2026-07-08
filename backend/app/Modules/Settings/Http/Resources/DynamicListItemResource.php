<?php

declare(strict_types=1);

namespace App\Modules\Settings\Http\Resources;

use App\Modules\Settings\Models\DynamicListItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DynamicListItem
 */
class DynamicListItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            // Full per-locale map (not a resolved string): the SPA switches
            // language without refetching, so it picks the label client-side.
            'label_translations' => $this->label_translations,
            'value' => $this->value,
            'sort_order' => $this->sort_order,
            'parent_id' => $this->parent_id,
            'is_active' => $this->is_active,
            'meta' => $this->meta,
        ];
    }
}
