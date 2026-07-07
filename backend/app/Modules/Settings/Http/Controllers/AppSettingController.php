<?php

declare(strict_types=1);

namespace App\Modules\Settings\Http\Controllers;

use App\Modules\Settings\Models\AppSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Scalar app-wide settings. Read is open to any authenticated user (the UI
 * shows e.g. the hold duration); writes require settings.manage (route-gated)
 * and only known keys are accepted — no free-form key creation.
 */
class AppSettingController extends Controller
{
    /** The editable keys and their validation rules. */
    private const KEYS = [
        'interest_hold_hours' => ['required', 'integer', 'min:1', 'max:8760'],
        'reserved_hold_hours' => ['required', 'integer', 'min:1', 'max:8760'],
        // Brute-force lockout: attempts before the account locks, and how long
        // the lock lasts (0 = until an admin unlocks it in Settings → Users).
        'login_max_attempts' => ['required', 'integer', 'min:1', 'max:10'],
        'login_lockout_minutes' => ['required', 'integer', 'min:0', 'max:10080'],
    ];

    public function index(): JsonResponse
    {
        return response()->json(['data' => AppSetting::remembered()]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(
            collect(self::KEYS)
                ->only(array_keys($request->all()))
                ->whenEmpty(fn () => abort(422, 'No known setting was sent.'))
                ->all(),
        );

        foreach ($data as $key => $value) {
            AppSetting::set($key, (string) $value);
        }

        return response()->json(['data' => AppSetting::remembered()]);
    }
}
