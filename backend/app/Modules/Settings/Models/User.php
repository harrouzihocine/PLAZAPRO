<?php

declare(strict_types=1);

namespace App\Modules\Settings\Models;

use App\Core\Concerns\Cancellable;
use App\Core\Concerns\LogsActivity;
use App\Core\Enums\RecordStatus;
use App\Core\Exceptions\RecordDeletionException;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'password',
        'role_id',
        'department_id',
        'phone',
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
            'password' => 'hashed',
            'is_active' => 'boolean',
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

    /** Route-level permission check, resolved through the single role. */
    public function hasPermission(string $slug): bool
    {
        return (bool) $this->role?->hasPermission($slug);
    }

    public function isAgent(): bool
    {
        return (bool) $this->role?->is_agent;
    }

    /** Hard delete is disabled — users are cancelled/deactivated, never removed. */
    public function delete(): bool
    {
        throw new RecordDeletionException('Users are cancelled, never deleted. Use cancel($reason).');
    }
}
