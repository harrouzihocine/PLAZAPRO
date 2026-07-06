<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Resources;

use App\Modules\Payments\Models\PaymentSchedule;
use App\Modules\Payments\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PaymentSchedule
 */
class PaymentScheduleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_project_id' => $this->client_project_id,
            'unit_id' => $this->unit_id,
            'installment_no' => $this->installment_no,
            'due_date' => optional($this->due_date)->toDateString(),
            'amount' => (string) $this->amount,
            'paid_amount' => (string) $this->paid_amount,
            'outstanding' => Money::sub((string) $this->amount, (string) $this->paid_amount),
            'state' => $this->state?->value,
            'status' => $this->status?->value,
        ];
    }
}
