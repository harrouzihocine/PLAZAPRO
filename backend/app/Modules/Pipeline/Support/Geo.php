<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Support;

/**
 * Small geodesy helpers for the dispatch GPS layer. Haversine is exact enough
 * at city scale (< 0.5% error); routed distances/times would need a routing
 * engine and Algeria has no live traffic feed anyway, so ETAs are estimates by
 * design: straight line × a road winding factor at urban driving speed.
 */
final class Geo
{
    private const EARTH_RADIUS_KM = 6371.0;

    /** Straight-line factor → typical road distance in a city. */
    private const ROAD_FACTOR = 1.35;

    /** Average urban driving speed (km/h) used for ETA estimates. */
    private const URBAN_SPEED_KMH = 25.0;

    public static function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return self::EARTH_RADIUS_KM * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    public static function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        return self::distanceKm($lat1, $lng1, $lat2, $lng2) * 1000;
    }

    /** Estimated driving minutes to a point (never 0 — arriving takes a moment). */
    public static function etaMinutes(float $distanceKm): int
    {
        return max(1, (int) round($distanceKm * self::ROAD_FACTOR / self::URBAN_SPEED_KMH * 60));
    }

    /**
     * Shortest distance from a point to a polyline (the off-route check).
     * Equirectangular projection around the point — exact enough at city
     * scale, and cheap enough to run on every en-route fix.
     *
     * @param  list<array{0: float, 1: float}>  $path  [lat, lng] vertices
     */
    public static function distanceToPathMeters(float $lat, float $lng, array $path): ?float
    {
        $count = count($path);
        if ($count === 0) {
            return null;
        }

        $mPerLat = 110_574.0;
        $mPerLng = 111_320.0 * cos(deg2rad($lat));
        $px = $lng * $mPerLng;
        $py = $lat * $mPerLat;

        $best = null;
        $ax = $path[0][1] * $mPerLng;
        $ay = $path[0][0] * $mPerLat;

        for ($i = 1; $i < $count; $i++) {
            $bx = $path[$i][1] * $mPerLng;
            $by = $path[$i][0] * $mPerLat;

            $dx = $bx - $ax;
            $dy = $by - $ay;
            $lenSq = $dx * $dx + $dy * $dy;
            $t = $lenSq > 0 ? max(0.0, min(1.0, (($px - $ax) * $dx + ($py - $ay) * $dy) / $lenSq)) : 0.0;

            $d = hypot($px - ($ax + $t * $dx), $py - ($ay + $t * $dy));
            $best = $best === null ? $d : min($best, $d);

            $ax = $bx;
            $ay = $by;
        }

        return $best;
    }
}
