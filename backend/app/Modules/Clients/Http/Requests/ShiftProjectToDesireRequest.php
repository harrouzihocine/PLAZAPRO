<?php

declare(strict_types=1);

namespace App\Modules\Clients\Http\Requests;

use App\Modules\Clients\Http\Requests\Concerns\ValidatesDesireFields;
use App\Modules\Settings\Http\Requests\Concerns\ValidatesCommuneBelongsToWilaya;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shift a deal back to the Desire list (client changed their mind). Carries the
 * desire criteria (ValidatesDesireFields, incl. required notes) to re-capture
 * what the client wants. Project lifecycle is back-office (projects.manage).
 */
class ShiftProjectToDesireRequest extends FormRequest
{
    use ValidatesCommuneBelongsToWilaya;
    use ValidatesDesireFields;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('projects.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->desireFieldRules();
    }

    public function withValidator(Validator $validator): void
    {
        $this->validateCommuneMatchesWilaya($validator);
        $this->validateDesireRanges($validator);
    }
}
