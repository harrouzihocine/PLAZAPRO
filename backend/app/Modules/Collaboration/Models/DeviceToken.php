<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Models;

use App\Modules\Settings\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An FCM device registration (Android shell push). Deliberately a plain Model,
 * NOT a BaseModel: tokens are infrastructure — they must be hard-deletable when
 * FCM reports them dead (UNREGISTERED) or the user logs out on that device.
 */
class DeviceToken extends Model
{
    protected $fillable = ['user_id', 'token', 'platform', 'last_seen_at'];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
