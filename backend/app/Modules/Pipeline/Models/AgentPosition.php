<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Models;

use App\Modules\Settings\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An on-duty GPS breadcrumb (insert-only; pruned by the scheduler). The
 * dispatcher's live map reads the latest row per agent; the geofence and ETA
 * logic run on each ingest in RecordAgentPosition.
 */
class AgentPosition extends Model
{
    protected $fillable = ['user_id', 'latitude', 'longitude', 'accuracy_m', 'recorded_at'];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'accuracy_m' => 'integer',
            'recorded_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The freshest position per user — one query, keyed by user id. Stale
     * fixes (older than $maxAgeMinutes) are dropped: a dot that hasn't moved
     * since yesterday is misinformation on a live map.
     *
     * @param  iterable<int>  $userIds
     * @return Collection<int, self>
     */
    public static function latestFor(iterable $userIds, int $maxAgeMinutes = 480): Collection
    {
        return self::query()
            ->whereIn('id', function ($query) use ($userIds) {
                $query->selectRaw('MAX(id)')
                    ->from('agent_positions')
                    ->whereIn('user_id', collect($userIds)->all())
                    ->groupBy('user_id');
            })
            ->where('recorded_at', '>=', now()->subMinutes($maxAgeMinutes))
            ->get()
            ->keyBy('user_id');
    }
}
