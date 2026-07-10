<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Actions;

use App\Modules\Collaboration\Notifications\DomainNotification;
use App\Modules\Pipeline\Events\DutyLocateRequested;
use App\Modules\Pipeline\Models\DutySession;
use App\Modules\Settings\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Ping on-duty agents for a fresh GPS fix — the dispatcher-pull half of the
 * battery contract (idle tracking stays coarse; precision runs only while
 * someone looks, or while the agent is en route).
 *
 * Two delivery paths per agent: a broadcast on their own channel (an open
 * page answers with one fix) and a silent FCM data message (the v1.7+ shell
 * wakes its duty service for a short precision burst; older shells show a
 * quiet "dispatch checked your position" tray line — transparency, not spam).
 * Throttled per agent so five dispatchers staring at the map cost one burst
 * a minute, not five.
 */
class LocateOnDutyAgents
{
    private const THROTTLE_SECONDS = 60;

    /**
     * @param  list<int>|null  $agentIds  null = every on-duty agent
     * @return int how many agents were actually pinged
     */
    public function handle(?array $agentIds = null): int
    {
        $onDuty = DutySession::query()->open()
            ->when($agentIds !== null, fn ($q) => $q->whereIn('user_id', $agentIds))
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->unique();

        $pinged = 0;

        foreach ($onDuty as $userId) {
            // One burst per agent per window, whoever asks.
            if (! Cache::add("duty-locate:{$userId}", 1, self::THROTTLE_SECONDS)) {
                continue;
            }

            DutyLocateRequested::dispatch($userId);

            User::query()->find($userId)?->notify(new DomainNotification(
                kind: 'locate_request',
                key: 'locate_request',
                link: '/my-day',
                channels: ['fcm'],
            ));

            $pinged++;
        }

        return $pinged;
    }
}
