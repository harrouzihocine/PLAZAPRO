<?php

declare(strict_types=1);

namespace App\Modules\Settings\Http\Resources;

use App\Modules\Settings\Models\Commune;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Commune
 */
class CommuneResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'wilaya_id' => $this->wilaya_id,
            'name' => $this->name,
            'daira_name' => $this->daira_name,
            'status' => $this->status?->value,
        ];
    }
}
