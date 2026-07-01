<?php

declare(strict_types=1);

namespace App\Modules\Settings\Http\Controllers;

use App\Modules\Analytics\Models\ActivityLog;
use App\Modules\Settings\Http\Requests\IssueTokenRequest;
use App\Modules\Settings\Http\Requests\LoginRequest;
use App\Modules\Settings\Http\Resources\UserResource;
use App\Modules\Settings\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Authentication for both first-party clients:
 *  - SPA (cookie/session) via login/logout — the Vue web app.
 *  - Stateless Bearer tokens via issueToken/revokeToken — mobile & external
 *    clients (see docs/phase-8-mobile-push.md).
 *
 * Thin controller: validation lives in the FormRequests; the credential-
 * accepting routes are throttled (see Settings/routes.php).
 */
class AuthController extends Controller
{
    public function login(LoginRequest $request): UserResource
    {
        $credentials = $request->validated();

        if (! Auth::attempt(['email' => $credentials['email'], 'password' => $credentials['password']], true)) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        if (! Auth::user()->is_active) {
            Auth::logout();
            throw ValidationException::withMessages([
                'email' => [__('This account is inactive.')],
            ]);
        }

        $request->session()->regenerate();

        $user = Auth::user();
        $user->forceFill(['last_login_at' => now()])->saveQuietly();
        $user->load('role.permissions');

        ActivityLog::record('login', $user);

        return new UserResource($user);
    }

    /**
     * Stateless login for mobile / external clients. Verifies credentials
     * without opening a session and returns a Sanctum personal access token.
     * RBAC is unchanged: the token authenticates as the user, and `can:`
     * checks still resolve through the user's single role.
     */
    public function issueToken(IssueTokenRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => [__('This account is inactive.')],
            ]);
        }

        // One token per device: re-login on the same device replaces the old
        // token rather than accumulating stale ones.
        $device = $credentials['device_name'];
        $user->tokens()->where('name', $device)->delete();

        $token = $user->createToken($device)->plainTextToken;

        $user->forceFill(['last_login_at' => now()])->saveQuietly();
        $user->load('role.permissions');

        ActivityLog::record('login', $user);

        return response()->json([
            'token' => $token,
            'user' => new UserResource($user),
        ]);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->load('role.permissions'));
    }

    /**
     * Revoke the token used for the current request (mobile logout). A no-op
     * for session-authenticated callers, which carry a transient token.
     */
    public function revokeToken(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->json(['message' => 'Token revoked.']);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logged out.']);
    }
}
