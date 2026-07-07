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
 *
 * Brute-force lockout: consecutive failed password attempts are counted per
 * account and, past the configured limit, the account locks — even the correct
 * password is refused — until an admin unlocks it in Settings → Users (see
 * User::isLoginLocked / recordFailedLoginAttempt).
 */
class AuthController extends Controller
{
    private const LOCKED_MESSAGE = 'This account is locked after too many failed sign-in attempts. Ask an administrator to unlock it.';

    public function login(LoginRequest $request): UserResource
    {
        $credentials = $request->validated();

        // Accept either an email or a username as the login identifier.
        $field = filter_var($credentials['login'], FILTER_VALIDATE_EMAIL) ? 'email' : 'username';

        // A locked account is refused before the password is even checked, so
        // an attacker can't keep verifying guesses against a locked account.
        $account = User::where($field, $credentials['login'])->first();
        $this->rejectIfLocked($account, 'login');

        if (! Auth::attempt([$field => $credentials['login'], 'password' => $credentials['password']], true)) {
            $this->handleFailedAttempt($account, 'login');
        }

        if (! Auth::user()->is_active) {
            Auth::logout();
            throw ValidationException::withMessages([
                'login' => [__('This account is inactive.')],
            ]);
        }

        $request->session()->regenerate();

        $user = Auth::user();
        $user->clearLoginLockout();
        $user->forceFill(['last_login_at' => now()])->saveQuietly();
        $user->load('role.permissions');

        ActivityLog::record('login', $user);

        return new UserResource($user);
    }

    /** Refuse a locked account outright (same shape as a credential failure). */
    private function rejectIfLocked(?User $account, string $errorKey): void
    {
        if ($account?->isLoginLocked()) {
            throw ValidationException::withMessages([
                $errorKey => [__(self::LOCKED_MESSAGE)],
            ]);
        }

        // A lock that timed out (login_lockout_minutes > 0) is cleared lazily
        // here so the stale locked_at doesn't linger in the users list.
        if ($account !== null && $account->locked_at !== null) {
            $account->clearLoginLockout();
        }
    }

    /**
     * Count the failed attempt against the account (when the identifier matched
     * one) and answer with the generic credentials error — or the locked notice
     * when this attempt tripped the lock.
     */
    private function handleFailedAttempt(?User $account, string $errorKey): never
    {
        $locked = $account !== null && $account->recordFailedLoginAttempt();

        throw ValidationException::withMessages([
            $errorKey => [$locked ? __(self::LOCKED_MESSAGE) : __('auth.failed')],
        ]);
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

        $this->rejectIfLocked($user, 'email');

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            $this->handleFailedAttempt($user, 'email');
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => [__('This account is inactive.')],
            ]);
        }

        $user->clearLoginLockout();

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
