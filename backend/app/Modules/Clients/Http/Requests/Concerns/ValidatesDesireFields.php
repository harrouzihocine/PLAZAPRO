<?php

declare(strict_types=1);

namespace App\Modules\Clients\Http\Requests\Concerns;

use App\Modules\Settings\Models\Commune;
use Illuminate\Contracts\Validation\Validator;

/**
 * The desire-profile fields, shared by every request that captures what a
 * client wants (upsert, shift-to-desire, the call log's Branch A). Notes are
 * REQUIRED — a desire without the story behind it is unusable for matching.
 * The structured fields mirror the unit form but every selector is
 * multi-valued ("F2 OR F3"): type / room number / contract type / floor from
 * the dynamic lists, wilayas + communes, area range, budget range, preferred
 * locations (sites).
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
            $p.'wilaya_ids' => ['nullable', 'array'],
            $p.'wilaya_ids.*' => ['integer', 'distinct', 'exists:wilayas,id'],
            $p.'commune_ids' => ['nullable', 'array'],
            $p.'commune_ids.*' => ['integer', 'distinct', 'exists:communes,id'],
            $p.'type_ids' => ['nullable', 'array'],
            $p.'type_ids.*' => ['integer', 'distinct', 'exists:dynamic_list_items,id'],
            $p.'room_number_ids' => ['nullable', 'array'],
            $p.'room_number_ids.*' => ['integer', 'distinct', 'exists:dynamic_list_items,id'],
            $p.'contract_type_ids' => ['nullable', 'array'],
            $p.'contract_type_ids.*' => ['integer', 'distinct', 'exists:dynamic_list_items,id'],
            $p.'floor_ids' => ['nullable', 'array'],
            $p.'floor_ids.*' => ['integer', 'distinct', 'exists:dynamic_list_items,id'],
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

    /**
     * The multi-select mirror of ValidatesCommuneBelongsToWilaya: every picked
     * commune must belong to one of the picked wilayas (and communes can't be
     * sent without their wilayas).
     */
    protected function validateDesireCommunesMatchWilayas(Validator $validator, string $prefix = ''): void
    {
        $p = $prefix === '' ? '' : $prefix.'.';

        $validator->after(function (Validator $v) use ($p) {
            $communeIds = array_filter((array) $this->input($p.'commune_ids', []));

            if ($communeIds === []) {
                return;
            }

            $wilayaIds = array_filter((array) $this->input($p.'wilaya_ids', []));

            $allBelong = $wilayaIds !== []
                && Commune::whereIn('id', $communeIds)
                    ->whereIn('wilaya_id', $wilayaIds)
                    ->count() === count($communeIds);

            if (! $allBelong) {
                $v->errors()->add($p.'commune_ids', 'Every selected commune must belong to one of the chosen wilayas.');
            }
        });
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
