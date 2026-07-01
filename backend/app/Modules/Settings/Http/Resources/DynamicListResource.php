<?php

declare(strict_types=1);

namespace App\Modules\Settings\Http\Resources;

use App\Modules\Settings\Models\DynamicList;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DynamicList
 */
class DynamicListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            'is_system' => $this->is_system,
            'status' => $this->status?->value,
            'items' => $this->when(
                $this->relationLoaded('activeItems') || $this->relationLoaded('items'),
                fn () => DynamicListItemResource::collection(
                    $this->relationLoaded('activeItems') ? $this->activeItems : $this->items
                ),
            ),
        ];
    }
}
