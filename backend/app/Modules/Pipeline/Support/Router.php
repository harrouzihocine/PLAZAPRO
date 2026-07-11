<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Support;

use Illuminate\Support\Facades\Http;

/**
 * Road-network routing via the self-hosted OSRM `osrm` docker service
 * (Algeria OSM extract — `scripts/setup-osrm.sh` builds the graph once).
 *
 * Every helper returns null when the engine is down, unbuilt or slow; callers
 * fall back to the straight-line estimates in Geo, so dispatch never DEPENDS
 * on OSRM being alive. Algeria has no live traffic feed — OSRM durations are
 * free-flow OSM speeds, so a flat urban-congestion factor is applied. Still an
 * estimate, but road-true: a site across the oued stops looking "500 m away".
 */
final class Router
{
    /** Free-flow OSRM minutes → plausible Algiers driving minutes. */
    private const CONGESTION_FACTOR = 1.3;

    private const TIMEOUT_S = 4;

    /**
     * Duration/distance matrix, sources × destinations.
     *
     * @param  list<array{0: float, 1: float}>  $sources  [lat, lng] pairs
     * @param  list<array{0: float, 1: float}>  $destinations  [lat, lng] pairs
     * @return list<list<array{km: float|null, minutes: int|null}>>|null null when OSRM is unreachable
     */
    public static function table(array $sources, array $destinations): ?array
    {
        if ($sources === [] || $destinations === []) {
            return null;
        }

        $points = array_merge($sources, $destinations);
        $coords = implode(';', array_map(fn (array $p) => $p[1].','.$p[0], $points));
        $sourceIdx = implode(';', range(0, count($sources) - 1));
        $destIdx = implode(';', range(count($sources), count($points) - 1));

        $json = self::call("/table/v1/driving/{$coords}", [
            'sources' => $sourceIdx,
            'destinations' => $destIdx,
            'annotations' => 'duration,distance',
        ]);

        if ($json === null || ($json['code'] ?? null) !== 'Ok') {
            return null;
        }

        return array_map(
            fn (array $durRow, array $distRow) => array_map(
                fn ($seconds, $meters) => [
                    'km' => $meters !== null ? round($meters / 1000, 1) : null,
                    'minutes' => $seconds !== null
                        ? max(1, (int) round($seconds / 60 * self::CONGESTION_FACTOR))
                        : null,
                ],
                $durRow,
                $distRow,
            ),
            $json['durations'] ?? [],
            $json['distances'] ?? [],
        );
    }

    /**
     * One driving route with its geometry — the off-route corridor.
     *
     * @param  array{0: float, 1: float}  $from  [lat, lng]
     * @param  array{0: float, 1: float}  $to  [lat, lng]
     * @return array{km: float, minutes: int, polyline: list<array{0: float, 1: float}>}|null
     */
    public static function route(array $from, array $to): ?array
    {
        $coords = $from[1].','.$from[0].';'.$to[1].','.$to[0];

        $json = self::call("/route/v1/driving/{$coords}", [
            'overview' => 'full',
            'geometries' => 'geojson',
        ]);

        $route = $json['routes'][0] ?? null;
        if ($json === null || ($json['code'] ?? null) !== 'Ok' || $route === null) {
            return null;
        }

        return [
            'km' => round($route['distance'] / 1000, 1),
            'minutes' => max(1, (int) round($route['duration'] / 60 * self::CONGESTION_FACTOR)),
            // GeoJSON is [lng, lat] — flip to the [lat, lng] the app speaks.
            'polyline' => array_map(
                fn (array $c) => [(float) $c[1], (float) $c[0]],
                $route['geometry']['coordinates'] ?? [],
            ),
        ];
    }

    /** @return array<string, mixed>|null */
    private static function call(string $path, array $query): ?array
    {
        try {
            $response = Http::connectTimeout(2)->timeout(self::TIMEOUT_S)
                ->get(rtrim(config('services.osrm.url'), '/').$path, $query);

            return $response->successful() ? $response->json() : null;
        } catch (\Throwable) {
            return null; // engine down/unbuilt — callers fall back to Geo
        }
    }
}
