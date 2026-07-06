<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Http\Requests\Concerns;

/**
 * Every concluded interaction (a call, a completed visit) must resolve: either
 * it schedules a `next_action`, or it carries a `closure` that ends the thread.
 * There is no "logged nothing". A closure is one of three explicit outcomes:
 *  - desire  → the client wants something we don't have; the project is shifted
 *              to the desire list (its structured `desire` profile is captured);
 *  - archive → the client passed; a reason (archive_reasons) + a note are kept;
 *  - deal    → the client buys; the picked units open THE deal.
 *
 * Pairs with ValidatesNextAction (exactly one of the two is sent) and, for the
 * desire branch, ValidatesDesireFields on the `closure.desire` prefix.
 */
trait ValidatesClosure
{
    /**
     * @return array<string, mixed>
     */
    protected function closureRules(): array
    {
        return [
            'closure' => ['array', 'required_without:next_action', 'prohibits:next_action'],
            'closure.type' => ['required_with:closure', 'in:desire,archive,deal'],
            // desire → the structured desire profile (fields via desireFieldRules).
            'closure.desire' => ['required_if:closure.type,desire', 'array'],
            // archive → a controlled reason + the story behind it.
            'closure.reason_id' => ['required_if:closure.type,archive', 'integer', 'exists:dynamic_list_items,id'],
            'closure.note' => ['required_if:closure.type,archive', 'string', 'max:2000'],
            // deal → the units the client commits to (specific boxes optional per unit —
            // each gets linked to its apartment; CreateDeal enforces the rules).
            'closure.units' => ['required_if:closure.type,deal', 'array', 'min:1'],
            'closure.units.*.unit_id' => ['required_with:closure.units', 'integer', 'exists:units,id'],
            'closure.units.*.box_ids' => ['nullable', 'array'],
            'closure.units.*.box_ids.*' => ['integer', 'distinct', 'exists:boxes,id'],
            'closure.notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
