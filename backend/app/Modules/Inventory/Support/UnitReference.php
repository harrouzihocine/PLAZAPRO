<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use App\Modules\Inventory\Models\Location;

/**
 * Builds the compact auto-generated unit reference from the unit's own specs
 * plus its project: `{LOC}-{ROOMS}-{BLOCK}{FLOOR}-{POS}` with empty parts
 * skipped (e.g. "AQU-F3-A2-05", "KAI-F2-B", "NOU-STUDIO-RDC"). Collisions get
 * a numeric suffix ("AQU-F3-A2-05-2"). The frontend twin (live preview while
 * typing in the unit form) is buildUnitRef() in
 * frontend/src/features/inventory/unitRef.js — keep the two in sync.
 */
class UnitReference
{
    public static function build(
        Location $location,
        ?string $roomsLabel,
        ?string $floorLabel,
        ?string $block,
        ?int $stackFloor,
        ?int $position,
    ): string {
        $parts = array_values(array_filter([
            self::locationCode($location),
            self::compact($roomsLabel),
            self::compact($block).(self::floorCode($floorLabel, $stackFloor) ?? ''),
            $position !== null ? str_pad((string) $position, 2, '0', STR_PAD_LEFT) : null,
        ], fn (?string $p) => $p !== null && $p !== ''));

        // Never emit an empty reference (a unit with no specs yet).
        return $parts === [] ? 'UNIT' : implode('-', $parts);
    }

    /**
     * First available reference: the base itself, else base-2, base-3, …
     * (case-insensitive against $taken).
     *
     * @param  iterable<int|string, string>  $taken
     */
    public static function dedupe(string $base, iterable $taken): string
    {
        $set = [];
        foreach ($taken as $ref) {
            $set[mb_strtolower(trim((string) $ref))] = true;
        }

        if (! isset($set[mb_strtolower($base)])) {
            return $base;
        }

        for ($n = 2; ; $n++) {
            if (! isset($set[mb_strtolower("{$base}-{$n}")])) {
                return "{$base}-{$n}";
            }
        }
    }

    /** First 3 alphanumeric characters of the project code (name as fallback). */
    private static function locationCode(Location $location): ?string
    {
        foreach ([$location->code, $location->name] as $source) {
            $clean = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $source));
            if ($clean !== '') {
                return substr($clean, 0, 3);
            }
        }

        return null;
    }

    /** Uppercased, whitespace stripped ("F3 + T" → "F3+T"). */
    private static function compact(?string $value): ?string
    {
        $clean = strtoupper((string) preg_replace('/\s+/', '', (string) $value));

        return $clean === '' ? null : $clean;
    }

    /**
     * Floor label → shortest code: "3rd Floor"→"3", "Ground Floor"→"RDC",
     * "Sous-sol 1"→"SS1"; a bare stack_floor number is the fallback.
     */
    private static function floorCode(?string $label, ?int $stackFloor): ?string
    {
        $label = trim((string) $label);

        if ($label !== '') {
            if (preg_match('/sous|basement/i', $label)) {
                return preg_match('/(\d+)/', $label, $m) ? 'SS'.$m[1] : 'SS';
            }
            if (preg_match('/ground|rdc|rez/i', $label)) {
                return 'RDC';
            }
            if (preg_match('/(\d+)/', $label, $m)) {
                return $m[1];
            }

            return self::compact(substr($label, 0, 3));
        }

        return $stackFloor !== null ? (string) $stackFloor : null;
    }
}
