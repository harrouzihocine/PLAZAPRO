<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Models;

use App\Modules\Settings\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One agent's driven day, aggregated nightly from agent_positions before the
 * retention prune (dispatch:mileage). Plain Model like DutySession: telemetry,
 * upserted in place, no activity log.
 */
class AgentMileageDay extends Model
{
    protected $fillable = ['user_id', 'day', 'km', 'fixes', 'duty_minutes'];

    protected function casts(): array
    {
        return [
            'day' => 'date',
            'km' => 'float',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
