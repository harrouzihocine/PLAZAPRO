<?php

declare(strict_types=1);

namespace App\Modules\Settings\Http\Resources;

use App\Modules\Settings\Models\Wilaya;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Wilaya
 */
class WilayaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'status' => $this->status?->value,
            'communes_count' => $this->whenCounted('communes'),
            'communes' => CommuneResource::collection($this->whenLoaded('communes')),
        ];
    }
}
