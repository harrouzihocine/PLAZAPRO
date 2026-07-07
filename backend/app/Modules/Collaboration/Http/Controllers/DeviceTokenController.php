<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Http\Controllers;

use App\Modules\Collaboration\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * FCM device registrations for the Android shell's push notifications. The
 * token is unique per DEVICE: registering claims it for the calling user (a
 * different user logging in on the same phone takes the row over — the
 * previous user must stop receiving that device's pushes), and `forget`
 * releases it on logout.
 */
class DeviceTokenController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:512'],
            'platform' => ['nullable', 'string', 'in:android,ios,web'],
        ]);

        DeviceToken::query()->updateOrCreate(
            ['token' => $validated['token']],
            [
                'user_id' => $request->user()->id,
                'platform' => $validated['platform'] ?? 'android',
                'last_seen_at' => now(),
            ],
        );

        return response()->json(['registered' => true]);
    }

    /** Called on logout — only the owner may release their device's token. */
    public function forget(Request $request): JsonResponse
    {
        $validated = $request->validate(['token' => ['required', 'string', 'max:512']]);

        DeviceToken::query()
            ->where('token', $validated['token'])
            ->where('user_id', $request->user()->id)
            ->delete();

        return response()->json(['forgotten' => true]);
    }
}
