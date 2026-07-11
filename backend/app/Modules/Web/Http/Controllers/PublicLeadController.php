<?php

declare(strict_types=1);

namespace App\Modules\Web\Http\Controllers;

use App\Core\Enums\RecordStatus;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Web\Actions\CreateWebLead;
use App\Modules\Web\Enums\WebLeadType;
use App\Modules\Web\Support\FormToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
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
        ]);

        $this->assertUnitIsPublic($data);

        $action->handle($data, $request);

        return $this->accepted();
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
