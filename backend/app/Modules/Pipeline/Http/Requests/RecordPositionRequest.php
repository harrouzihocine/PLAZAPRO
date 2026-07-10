<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * One GPS fix from an on-duty device. Coordinates are the only requirement;
 * accuracy and the device-side timestamp are best-effort extras (the server
 * clock wins when the device omits or garbles its own).
 */
class RecordPositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isAgent();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy_m' => ['nullable', 'integer', 'min:0', 'max:65000'],
            'recorded_at' => ['nullable', 'date', 'before_or_equal:now'],
        ];
    }
}
