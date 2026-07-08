<?php

declare(strict_types=1);

namespace App\Modules\Settings\Http\Controllers;

use App\Modules\Collaboration\Notifications\DomainNotification;
use App\Modules\Settings\Actions\ProcessAvatar;
use App\Modules\Settings\Actions\UpdateProfile;
use App\Modules\Settings\Http\Requests\UpdateProfileRequest;
use App\Modules\Settings\Http\Requests\UploadAvatarRequest;
use App\Modules\Settings\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;

/**
 * The authenticated user managing their OWN account: editing their details,
 * password and profile photo. Every endpoint acts on $request->user() — never
 * an id from the route — so a user can only ever change themselves. Changing a
 * username, role or active-state is admin-only and lives in UserController.
 */
class ProfileController extends Controller
{
    public function update(UpdateProfileRequest $request, UpdateProfile $action): UserResource
    {
        $user = $action->handle($request->user(), $request->validated());

        return new UserResource($user->load('role.permissions'));
    }

    /**
     * Per-category push toggles (system-tray only — the bell always delivers).
     * Partial updates merge over what's saved, so a future category added to
     * PUSH_CATEGORIES stays on for everyone until they explicitly turn it off.
     */
    public function updatePushPrefs(Request $request): UserResource
    {
        $rules = [];
        foreach (array_keys(DomainNotification::PUSH_CATEGORIES) as $category) {
            $rules[$category] = ['sometimes', 'boolean'];
        }

        $user = $request->user();
        $user->forceFill([
            'push_prefs' => array_merge($user->push_prefs ?? [], array_map(
                fn ($v) => (bool) $v,
                $request->validate($rules),
            )),
        ])->save();

        return new UserResource($user->load('role.permissions'));
    }

    /**
     * UI language (en/fr/ar). Saved on the profile so backend-built text —
     * validation replies via SetLocale, notifications/push/digest via
     * preferredLocale() — follows the user everywhere, on every device.
     */
    public function updateLocale(Request $request): UserResource
    {
        $validated = $request->validate([
            'locale' => ['required', 'string', 'in:'.implode(',', \App\Http\Middleware\SetLocale::SUPPORTED)],
        ]);

        $user = $request->user();
        $user->forceFill(['locale' => $validated['locale']])->save();

        return new UserResource($user->load('role.permissions'));
    }

    public function uploadAvatar(UploadAvatarRequest $request, ProcessAvatar $action): UserResource
    {
        $user = $request->user();
        $old = $user->avatar_path;

        $path = $action->handle($request->file('avatar'));
        $user->update(['avatar_path' => $path]);

        $this->forget($old);

        return new UserResource($user->fresh()->load('role.permissions'));
    }

    public function deleteAvatar(Request $request): UserResource
    {
        $user = $request->user();
        $old = $user->avatar_path;

        $user->update(['avatar_path' => null]);
        $this->forget($old);

        return new UserResource($user->fresh()->load('role.permissions'));
    }

    /** Best-effort cleanup of a superseded avatar file. */
    private function forget(?string $path): void
    {
        if ($path !== null) {
            Storage::disk('media')->delete($path);
        }
    }
}
