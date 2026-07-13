<?php

declare(strict_types=1);

namespace App\Modules\Web\Http\Controllers;

use App\Core\Enums\RecordStatus;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Settings\Models\Commune;
use App\Modules\Settings\Models\DynamicList;
use App\Modules\Web\Actions\CreateWebLead;
use App\Modules\Web\Enums\WebLeadType;
use App\Modules\Web\Support\FormToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;

/**
 * The public lead form. Deliberately a plain-Request controller (no
 * FormRequest): the honeypot must short-circuit BEFORE validation with a fake
 * success — a bot that filled the trap must never learn it was caught, and a
 * 422 with field errors would be exactly that lesson.
 *
 * Anti-spam layers: throttle:public-leads (route) → honeypot → min-fill-time
 * token → validation. The response never echoes internal ids.
 */
class PublicLeadController extends Controller
{
    /** The desire form's flat payload keys — packed into `criteria` on save. */
    private const CRITERIA_KEYS = [
        'wilaya_ids', 'commune_ids', 'type_ids', 'room_number_ids',
        'budget_min', 'budget_max',
    ];

    public function __invoke(Request $request, CreateWebLead $action): JsonResponse
    {
        // Layer 2 — honeypot. The `website` field is invisible to humans.
        if (filled($request->input('website'))) {
            return $this->accepted();
        }

        // Layer 3 — min-fill-time. Humans read before they send.
        if (! FormToken::passes($request->input('form_token'))) {
            throw ValidationException::withMessages(['form_token' => 'Please try again.']);
        }

        $isDesire = $request->input('type') === WebLeadType::Desire->value;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:32', 'regex:/^[0-9+\s().\-]{6,}$/'],
            'message' => ['nullable', 'string', 'max:2000'],
            'type' => ['required', new Enum(WebLeadType::class)],
            // Leads may only reference what the public can see: a published,
            // live project (and one of ITS units).
            'location_id' => ['nullable', 'integer', Rule::exists('locations', 'id')
                ->where('is_published', true)
                ->where('status', RecordStatus::Active->value)],
            'unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'preferred_date' => ['nullable', 'date', 'after_or_equal:today'],
            'preferred_time' => ['nullable', 'string', 'max:8'],
            'source_url' => ['nullable', 'string', 'max:500'],
            ...($isDesire ? $this->desireRules() : []),
        ]);

        $this->assertUnitIsPublic($data);

        if ($isDesire) {
            $this->assertCommunesMatchWilayas($data);
            $this->assertBudgetRange($data);
            $data['criteria'] = $this->packCriteria($data);
        }

        $action->handle(Arr::except($data, self::CRITERIA_KEYS), $request);

        return $this->accepted();
    }

    /**
     * The desire criteria — every selector multi-valued like the internal
     * desire profile, but only what a visitor can meaningfully answer (the
     * agent captures the rest on the qualifying call). Dynamic-list ids are
     * scoped to THEIR list, so an id from an unrelated vocabulary 422s.
     */
    private function desireRules(): array
    {
        return [
            'wilaya_ids' => ['nullable', 'array', 'max:5'],
            'wilaya_ids.*' => ['integer', 'distinct', Rule::exists('wilayas', 'id')
                ->where('status', RecordStatus::Active->value)],
            'commune_ids' => ['nullable', 'array', 'max:10'],
            'commune_ids.*' => ['integer', 'distinct', Rule::exists('communes', 'id')
                ->where('status', RecordStatus::Active->value)],
            'type_ids' => ['nullable', 'array', 'max:5'],
            'type_ids.*' => ['integer', 'distinct', $this->listItemRule('project_types')],
            'room_number_ids' => ['nullable', 'array', 'max:5'],
            'room_number_ids.*' => ['integer', 'distinct', $this->listItemRule('room_numbers')],
            'budget_min' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'budget_max' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
        ];
    }

    private function listItemRule(string $listKey): \Illuminate\Validation\Rules\Exists
    {
        return Rule::exists('dynamic_list_items', 'id')
            ->where('dynamic_list_id', DynamicList::query()->where('key', $listKey)->value('id') ?? 0)
            ->where('is_active', true)
            ->where('status', RecordStatus::Active->value);
    }

    /** Same coherence rule as the internal form: communes ⊆ chosen wilayas. */
    private function assertCommunesMatchWilayas(array $data): void
    {
        $communeIds = array_filter($data['commune_ids'] ?? []);

        if ($communeIds === []) {
            return;
        }

        $wilayaIds = array_filter($data['wilaya_ids'] ?? []);

        $allBelong = $wilayaIds !== []
            && Commune::whereIn('id', $communeIds)
                ->whereIn('wilaya_id', $wilayaIds)
                ->count() === count($communeIds);

        if (! $allBelong) {
            throw ValidationException::withMessages([
                'commune_ids' => 'Every selected commune must belong to one of the chosen wilayas.',
            ]);
        }
    }

    /** Min ≤ max, checked only when both ends were answered. */
    private function assertBudgetRange(array $data): void
    {
        $min = $data['budget_min'] ?? null;
        $max = $data['budget_max'] ?? null;

        if ($min !== null && $max !== null && (float) $max < (float) $min) {
            throw ValidationException::withMessages([
                'budget_max' => 'The maximum budget must be greater than or equal to the minimum.',
            ]);
        }
    }

    /** Only the answered criteria are stored; nothing answered → null. */
    private function packCriteria(array $data): ?array
    {
        $criteria = [];

        foreach (['wilaya_ids', 'commune_ids', 'type_ids', 'room_number_ids'] as $key) {
            $ids = array_values(array_filter($data[$key] ?? []));
            if ($ids !== []) {
                $criteria[$key] = array_map(intval(...), $ids);
            }
        }

        foreach (['budget_min', 'budget_max'] as $key) {
            if (($data[$key] ?? null) !== null) {
                $criteria[$key] = (float) $data[$key];
            }
        }

        return $criteria === [] ? null : $criteria;
    }

    /** The unit must belong to a published project — and to the sent project. */
    private function assertUnitIsPublic(array $data): void
    {
        if (($data['unit_id'] ?? null) === null) {
            return;
        }

        $unit = Unit::query()->active()
            ->whereHas('location', fn ($q) => $q
                ->where('status', RecordStatus::Active->value)
                ->where('is_published', true))
            ->when(
                ($data['location_id'] ?? null) !== null,
                fn ($q) => $q->where('location_id', $data['location_id']),
            )
            ->find($data['unit_id']);

        if ($unit === null) {
            throw ValidationException::withMessages(['unit_id' => 'This apartment is not available.']);
        }
    }

    private function accepted(): JsonResponse
    {
        return response()->json(['data' => ['ok' => true]], 201);
    }
}
