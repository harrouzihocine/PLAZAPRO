<?php

declare(strict_types=1);

namespace App\Modules\Clients\Http\Requests;

use App\Modules\Clients\Http\Requests\Concerns\RequiresFirstCall;
use App\Modules\Clients\Http\Requests\Concerns\ValidatesDesireFields;
use App\Modules\Settings\Http\Requests\Concerns\ValidatesCommuneBelongsToWilaya;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Capturing/updating a client's desire is part of qualifying a lead, so it is
 * gated by clients.create (which agents hold), not clients.manage. The field
 * set (incl. required notes) lives in ValidatesDesireFields.
 */
class UpsertDesireRequest extends FormRequest
{
    use RequiresFirstCall;
    use ValidatesCommuneBelongsToWilaya;
    use ValidatesDesireFields;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('clients.create');
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

        // Requirements are captured during (or after) the qualifying call.
        $this->requireFirstCall($validator, $this->route('client'), "capturing the client's requirements");

        $this->validateDesireRanges($validator);
    }
}
