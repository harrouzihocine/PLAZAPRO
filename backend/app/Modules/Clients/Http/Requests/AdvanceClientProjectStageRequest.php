<?php

declare(strict_types=1);

namespace App\Modules\Clients\Http\Requests;

use App\Modules\Clients\Enums\ClientProjectStage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class AdvanceClientProjectStageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('projects.advance');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'stage' => ['required', new Enum(ClientProjectStage::class)],
        ];
    }
}
