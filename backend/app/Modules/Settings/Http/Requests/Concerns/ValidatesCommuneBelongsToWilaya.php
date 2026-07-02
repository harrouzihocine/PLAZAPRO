<?php

declare(strict_types=1);

namespace App\Modules\Settings\Http\Requests\Concerns;

use App\Modules\Settings\Models\Commune;
use Illuminate\Contracts\Validation\Validator;

/**
 * Shared cross-field check for any request that carries both `wilaya_id` and
 * `commune_id`: a chosen commune must actually belong to the chosen wilaya (and
 * a commune can't be set without its wilaya). Requests call
 * `validateCommuneMatchesWilaya()` from their own `withValidator()`.
 */
trait ValidatesCommuneBelongsToWilaya
{
    protected function validateCommuneMatchesWilaya(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $communeId = $this->input('commune_id');

            if ($communeId === null) {
                return;
            }

            $belongs = Commune::where('id', $communeId)
                ->where('wilaya_id', $this->input('wilaya_id'))
                ->exists();

            if (! $belongs) {
                $v->errors()->add('commune_id', 'The selected commune does not belong to the chosen wilaya.');
            }
        });
    }
}
