<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Resources;

use App\Modules\Payments\Models\Versement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Versement
 */
class VersementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_project_id' => $this->client_project_id,
            'unit_id' => $this->unit_id,
            'amount' => (string) $this->amount,
            'paid_on' => optional($this->paid_on)->toDateString(),
            'reference' => $this->reference,
            'schedule_item_id' => $this->schedule_item_id,
            'document_id' => $this->document_id,
            'supersedes_id' => $this->supersedes_id,
            'status' => $this->status?->value,
            'cancellation_reason' => $this->cancellation_reason,
            'refunded_at' => $this->refunded_at,
            'refund_reason' => $this->refund_reason,
            'refunded_by' => $this->whenLoaded('refunder', fn () => $this->refunder ? [
                'id' => $this->refunder->id,
                'name' => $this->refunder->name,
            ] : null),
            'method' => $this->whenLoaded('method', fn () => $this->method ? [
                'id' => $this->method->id,
                'label' => $this->method->label,
            ] : null),
            'recorder' => $this->whenLoaded('recorder', fn () => $this->recorder ? [
                'id' => $this->recorder->id,
                'name' => $this->recorder->name,
            ] : null),
        ];
    }
}
