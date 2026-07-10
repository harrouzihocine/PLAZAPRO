<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Models;

use App\Modules\Settings\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One on-duty stretch of a field agent (see the migration for the privacy
 * contract). Plain Model on purpose: telemetry rows are closed, not cancelled,
 * and need no activity log of their own.
 */
class DutySession extends Model
{
    protected $fillable = ['user_id', 'started_at', 'ended_at'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('ended_at');
    }

    /** The user's open session, if they are on duty right now. */
    public static function openFor(int $userId): ?self
    {
        return self::query()->open()->where('user_id', $userId)->latest('started_at')->first();
    }
}
