<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Support;

use App\Modules\Pipeline\Models\AgentPosition;
use App\Modules\Pipeline\Models\DutySession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Distance-driven arithmetic over one agent's breadcrumbs for one day.
 *
 * Two kinds of segments meet here: dense en-route fixes (every 20 s / 30 m,
 * tracing the actual road) and sparse 5-minute duty snapshots (straight lines
 * that cut corners). Short segments are summed raw; long ones get the road
 * winding factor, so neither style over- or under-counts badly. GPS glitches
 * (a fix teleporting across town) are dropped by an implied-speed cap.
 */
final class Mileage
{
    /** Segments longer than this are snapshot gaps — straight lines cut corners. */
    private const SPARSE_KM = 0.5;

    /** Straight snapshot gap → typical road distance (same factor as Geo). */
    private const ROAD_FACTOR = 1.35;

    /** Implied km/h above this = GPS glitch, not driving. Segment dropped. */
    private const MAX_SPEED_KMH = 130.0;

    /**
     * @return array{km: float, fixes: int, duty_minutes: int}
     */
    public static function forDay(int $userId, Carbon $day): array
    {
        $positions = AgentPosition::query()
            ->where('user_id', $userId)
            ->whereBetween('recorded_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
            ->orderBy('recorded_at')
            ->get(['latitude', 'longitude', 'recorded_at']);

        return [
            'km' => self::pathKm($positions),
            'fixes' => $positions->count(),
            'duty_minutes' => self::dutyMinutes($userId, $day),
        ];
    }

    /**
     * @param  Collection<int, AgentPosition>  $positions  ordered by recorded_at
     */
    public static function pathKm(Collection $positions): float
    {
        $km = 0.0;
        $prev = null;

        foreach ($positions as $position) {
            if ($prev !== null) {
                $leg = Geo::distanceKm(
                    (float) $prev->latitude, (float) $prev->longitude,
                    (float) $position->latitude, (float) $position->longitude,
                );
                $hours = max(1, $prev->recorded_at->diffInSeconds($position->recorded_at)) / 3600;

                if ($leg / $hours <= self::MAX_SPEED_KMH) {
                    $km += $leg > self::SPARSE_KM ? $leg * self::ROAD_FACTOR : $leg;
                }
            }
            $prev = $position;
        }

        return round($km, 2);
    }

    private static function dutyMinutes(int $userId, Carbon $day): int
    {
        $dayStart = $day->copy()->startOfDay();
        $dayEnd = $day->copy()->endOfDay();

        return (int) DutySession::query()
            ->where('user_id', $userId)
            ->where('started_at', '<=', $dayEnd)
            ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>=', $dayStart))
            ->get(['started_at', 'ended_at'])
            ->sum(function (DutySession $session) use ($dayStart, $dayEnd) {
                $from = $session->started_at->greaterThan($dayStart) ? $session->started_at : $dayStart;
                $until = ($session->ended_at ?? now())->lessThan($dayEnd) ? ($session->ended_at ?? now()) : $dayEnd;

                return $from->lessThan($until) ? $from->diffInMinutes($until) : 0;
            });
    }
}
