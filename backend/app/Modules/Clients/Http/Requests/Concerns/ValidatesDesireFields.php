<?php

declare(strict_types=1);

namespace App\Modules\Clients\Http\Requests\Concerns;

use Illuminate\Contracts\Validation\Validator;

/**
 * The desire-profile fields, shared by every request that captures what a
 * client wants (upsert, shift-to-desire, the call log's Branch A). Notes are
 * REQUIRED — a desire without the story behind it is unusable for matching.
 * The structured fields mirror the unit form: type + floor from the dynamic
 * lists, area range, budget range, preferred locations (sites).
 */
trait ValidatesDesireFields
{
    /**
     * @return array<string, mixed>
     */
    protected function desireFieldRules(string $prefix = ''): array
    {
        $p = $prefix === '' ? '' : $prefix.'.';

        // Top-level: notes are plainly required. Nested (the call log's optional
        // `desire` branch): required only when the branch is sent at all.
        $notesRule = $prefix === '' ? 'required' : 'required_with:'.$prefix;

        return [
            $p.'wilaya_id' => ['nullable', 'integer', 'exists:wilayas,id'],
            $p.'commune_id' => ['nullable', 'integer', 'exists:communes,id'],
            $p.'type_id' => ['nullable', 'integer', 'exists:dynamic_list_items,id'],
            $p.'room_number_id' => ['nullable', 'integer', 'exists:dynamic_list_items,id'],
            $p.'contract_type_id' => ['nullable', 'integer', 'exists:dynamic_list_items,id'],
            $p.'floor_id' => ['nullable', 'integer', 'exists:dynamic_list_items,id'],
            $p.'floor_pref' => ['nullable', 'string', 'max:255'],
            $p.'area_min' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            $p.'area_max' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            $p.'rooms_min' => ['nullable', 'integer', 'min:0', 'max:50'],
            $p.'budget_min' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            $p.'budget_max' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            $p.'location_ids' => ['nullable', 'array'],
            $p.'location_ids.*' => ['integer', 'distinct', 'exists:locations,id'],
            $p.'notes' => [$notesRule, 'string', 'max:5000'],
        ];
    }

    /** Enforce min ≤ max on the budget and area ranges (only when both sent). */
    protected function validateDesireRanges(Validator $validator, string $prefix = ''): void
    {
        $p = $prefix === '' ? '' : $prefix.'.';

        $validator->after(function (Validator $v) use ($p) {
            foreach (['budget' => 'budget', 'area' => 'area'] as $field) {
                $min = $this->input($p.$field.'_min');
                $max = $this->input($p.$field.'_max');
                if ($min !== null && $max !== null && (float) $max < (float) $min) {
                    $v->errors()->add(
                        $p.$field.'_max',
                        'The maximum '.$field.' must be greater than or equal to the minimum.',
                    );
                }
            }
        });
    }
}
