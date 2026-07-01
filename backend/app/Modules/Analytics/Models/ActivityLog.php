<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * The append-only audit log. It is written automatically by BaseModel (via the
 * LogsActivity trait) and is never updated or deleted.
 *
 * Note: this model intentionally does NOT extend BaseModel — the log itself is
 * not audited or cancellable.
 */
class ActivityLog extends Model
{
    /** Append-only: there is no updated_at column. */
    public const UPDATED_AT = null;

    protected $table = 'activity_log';

    protected $guarded = [];

    protected $casts = [
        'changes' => 'array',
    ];

    public static function record(string $action, ?Model $subject = null, array $changes = []): self
    {
        $user = Auth::user();
        $request = request();

        return static::create([
            'user_id' => $user?->getAuthIdentifier(),
            'role_at_time' => $user?->role?->name,
            'action' => $action,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'changes' => $changes !== [] ? $changes : null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);
    }

    /** The log is immutable — block updates. */
    public function update(array $attributes = [], array $options = []): bool
    {
        throw new RuntimeException('The activity log is append-only and cannot be updated.');
    }

    /** The log is immutable — block deletes. */
    public function delete(): bool
    {
        throw new RuntimeException('The activity log is append-only and cannot be deleted.');
    }
}
