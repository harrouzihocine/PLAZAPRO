<?php

declare(strict_types=1);

namespace App\Modules\Settings\Models;

use App\Core\Concerns\Cancellable;
use App\Core\Concerns\LogsActivity;
use App\Core\Enums\RecordStatus;
use App\Core\Exceptions\RecordDeletionException;
use App\Modules\Analytics\Models\ActivityLog;
use App\Modules\Collaboration\Notifications\DomainNotification;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * A system user. Exactly one role. Users are deactivated/cancelled, never
 * hard-deleted, and their changes are audited (Cancellable + LogsActivity).
 */
class User extends Authenticatable implements MustVerifyEmail
{
    use Cancellable;      // deactivate/cancel instead of delete
    use HasApiTokens;     // Sanctum
    use HasFactory;
    use LogsActivity;     // audit trail
    use Notifiable;

    protected $fillable = [
        'name',
        'email',
        'username',
        'password',
        'role_id',
        'department_id',
        'phone',
        'avatar_path',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'locked_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'push_prefs' => 'array',
            'status' => RecordStatus::class,
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** FCM registrations of this user's devices (Android shell push). */
    public function deviceTokens(): HasMany
    {
        return $this->hasMany(\App\Modules\Collaboration\Models\DeviceToken::class);
    }

    /**
     * Does this user want a system-tray push for this notification kind?
     * Consulted by DomainNotification::via() for the FCM channel only — the
     * bell feed and live broadcast are never silenced. Defaults are all-on:
     * a null column, a missing category key, and any kind outside the
     * category map (security notices, future kinds) all deliver.
     */
    public function wantsPushFor(string $kind): bool
    {
        $category = DomainNotification::pushCategoryFor($kind);

        if ($category === null) {
            return true;
        }

        return (bool) ($this->push_prefs[$category] ?? true);
    }

    /**
     * Public URL for the profile photo, or null. The avatar itself lives on the
     * private `media` disk and is streamed through a permission-gated endpoint
     * (never a public path); `?v=` busts the browser cache when it changes.
     */
    public function avatarUrl(): ?string
    {
        if ($this->avatar_path === null) {
            return null;
        }

        // Root-relative on purpose: the <img> must resolve against whatever
        // origin the SPA is on (localhost:8080, a LAN IP, …) so the same-origin
        // session cookie is sent — an absolute url() built from APP_URL would
        // point at the wrong host/port. `?v=` busts the cache when it changes.
        return '/api/v1/users/'.$this->id.'/avatar?v='.($this->updated_at?->timestamp ?? 0);
    }

    /**
     * Whether the account is currently locked out of login (too many failed
     * password attempts). Owner's rule: a lock NEVER expires — only a user
     * with users.unlock clears it in Settings → Users (or `user:unlock` on
     * the console). Setting `login_lockout_minutes` (> 0) can opt into a
     * timed auto-expiry, but the default is 0 = until unlocked.
     */
    public function isLoginLocked(): bool
    {
        if ($this->locked_at === null) {
            return false;
        }

        $minutes = AppSetting::integer('login_lockout_minutes', 0);

        return $minutes <= 0 || $this->locked_at->addMinutes($minutes)->isFuture();
    }

    /**
     * Count one failed password attempt; lock the account once the configured
     * limit (`login_max_attempts`) is reached. Returns true when the account
     * is locked after this attempt. Audited — the log row carries the caller's
     * IP and user agent, so a brute-force source is traceable — and every
     * users.unlock holder is notified live (bell + flash) so someone can act.
     */
    public function recordFailedLoginAttempt(): bool
    {
        // A previous lock that timed out starts a fresh window.
        if ($this->locked_at !== null && ! $this->isLoginLocked()) {
            $this->forceFill(['failed_login_attempts' => 0, 'locked_at' => null]);
        }

        $attempts = $this->failed_login_attempts + 1;
        $lock = $this->locked_at === null && $attempts >= AppSetting::integer('login_max_attempts', 3);

        $this->forceFill([
            'failed_login_attempts' => $attempts,
            'locked_at' => $lock ? now() : $this->locked_at,
        ])->saveQuietly();

        if ($lock) {
            ActivityLog::record('account_locked', $this, ['attempts' => $attempts]);
            $this->notifyUnlockHolders($attempts);
        }

        return $this->isLoginLocked();
    }

    /**
     * Tell everyone who can unlock accounts (users.unlock, plus super admins,
     * who pass every gate) that this account just locked itself out.
     */
    private function notifyUnlockHolders(int $attempts): void
    {
        $unlockers = self::query()->active()
            ->where('is_active', true)
            ->where(fn ($q) => $q
                ->whereHas('role.permissions', fn ($p) => $p->where('slug', 'users.unlock'))
                ->orWhereHas('role', fn ($r) => $r->where('slug', 'super-admin')))
            ->get();

        foreach ($unlockers as $unlocker) {
            $unlocker->notify(new DomainNotification(
                kind: 'account_locked',
                title: 'Account locked: '.$this->name,
                body: $attempts.' failed sign-in attempts. Unlock it from the Users page.',
                link: '/settings/users',
                subjectType: self::class,
                subjectId: $this->id,
            ));
        }
    }

    /** Reset the failed-attempt counter and any lock (successful login / admin unlock). */
    public function clearLoginLockout(): void
    {
        if ($this->failed_login_attempts === 0 && $this->locked_at === null) {
            return;
        }

        $this->forceFill(['failed_login_attempts' => 0, 'locked_at' => null])->saveQuietly();
    }

    /** Route-level permission check, resolved through the single role. */
    public function hasPermission(string $slug): bool
    {
        return (bool) $this->role?->hasPermission($slug);
    }

    public function isAgent(): bool
    {
        return (bool) $this->role?->is_agent;
    }

    /** The all-powerful role. Only a super admin may modify/remove another super admin. */
    public function isSuperAdmin(): bool
    {
        return $this->role?->slug === 'super-admin';
    }

    /**
     * Broadcast this user's notifications on a stable, opaque channel name
     * (users.{id}) instead of the default namespaced class path. The matching
     * auth callback lives in routes/channels.php.
     */
    public function receivesBroadcastNotificationsOn(): string
    {
        return 'users.'.$this->id;
    }

    /** Hard delete is disabled — users are cancelled/deactivated, never removed. */
    public function delete(): bool
    {
        throw new RecordDeletionException('Users are cancelled, never deleted. Use cancel($reason).');
    }
}
