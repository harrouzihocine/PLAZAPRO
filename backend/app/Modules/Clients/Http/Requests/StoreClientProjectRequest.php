<?php

declare(strict_types=1);

namespace App\Modules\Clients\Http\Requests;

use App\Modules\Clients\Enums\ClientProjectStage;
use App\Modules\Clients\Http\Requests\Concerns\RequiresFirstCall;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreClientProjectRequest extends FormRequest
{
    use RequiresFirstCall;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('clients.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'stage' => ['sometimes', new Enum(ClientProjectStage::class)],
            'total_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->requireFirstCall($validator, $this->route('client'), 'opening a deal');
    }
}
