<?php

declare(strict_types=1);

namespace App\Modules\Settings\Actions;

use App\Modules\Settings\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Force-end a user's web sessions. Deleting the rows kills the sessions
 * themselves (database session driver), but every login also plants a
 * remember-me cookie, and Laravel keeps ONE remember token per user — without
 * cycling it, a "logged-out" device would silently sign back in through its
 * recaller. So the token is always cycled too, which voids remember-me on
 * every device at once; when the actor is the affected user, pass
 * $refreshRecaller so their own device is handed a fresh cookie and stays
 * remembered.
 */
class RevokeUserSessions
{
    /** End every session except $keepSessionId (null ⇒ end all). Returns the count ended. */
    public function except(User $user, ?string $keepSessionId, bool $refreshRecaller = false): int
    {
        $ended = DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->getKey())
            ->when($keepSessionId !== null, fn ($q) => $q->where('id', '!=', $keepSessionId))
            ->delete();

        $this->cycleRememberToken($user, $refreshRecaller);

        return $ended;
    }

    /** End one specific session. */
    public function only(User $user, string $sessionId, bool $refreshRecaller = false): void
    {
        DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->getKey())
            ->where('id', $sessionId)
            ->delete();

        $this->cycleRememberToken($user, $refreshRecaller);
    }

    private function cycleRememberToken(User $user, bool $refreshRecaller): void
    {
        $user->setRememberToken(Str::random(60));
        $user->saveQuietly();

        if ($refreshRecaller) {
            // Same id|token|password-hash shape SessionGuard::queueRecallerCookie builds.
            $guard = Auth::guard('web');
            $jar = $guard->getCookieJar();

            $jar->queue($jar->forever(
                $guard->getRecallerName(),
                $user->getAuthIdentifier().'|'.$user->getRememberToken().'|'.$user->getAuthPassword(),
            ));
        }
    }
}
