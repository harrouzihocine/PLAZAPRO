<?php

declare(strict_types=1);

namespace App\Support;

/**
 * DZ phone formatting to the app convention "+213 XXX XX XX XX" (the 2026-07-14
 * switch away from the old 0-form). Algerian national numbers are stored in
 * international form; foreign numbers and anything unrecognized are left
 * untouched by normalize(). Duplicate matching is unaffected — it keys on
 * clients.phone_nsn (last 9 digits), which is format-agnostic.
 *
 * Single source of truth shared by the client model mutator (app input) and the
 * legacy importer (LegacyImport\Support\Transform) so both produce identical output.
 */
class DzPhone
{
    /**
     * Group a bare DZ national number (digits only, leading trunk "0") into the
     * "+213 …" form, or null when it is not a DZ national number:
     *   10 digits (0 + 9)  → "+213 XXX XX XX XX"  (mobile, grouped 3-2-2-2)
     *    9 digits (0 + 8)  → "+213 XX XX XX XX"    (landline, grouped 2-2-2-2)
     */
    public static function format(string $digits): ?string
    {
        if (! str_starts_with($digits, '0')) {
            return null;
        }

        $nsn = substr($digits, 1);

        return match (strlen($digits)) {
            10 => '+213 '.substr($nsn, 0, 3).' '.substr($nsn, 3, 2).' '.substr($nsn, 5, 2).' '.substr($nsn, 7, 2),
            9 => '+213 '.substr($nsn, 0, 2).' '.substr($nsn, 2, 2).' '.substr($nsn, 4, 2).' '.substr($nsn, 6, 2),
            default => null,
        };
    }

    /**
     * Normalize free-text phone input to the DZ "+213 …" convention when it is a
     * recognizable DZ national number; otherwise return the trimmed input
     * unchanged (foreign +E164, already normalized, or malformed). Empty → null.
     * Idempotent: an already "+213 …" value passes straight through.
     */
    public static function normalize(?string $raw): ?string
    {
        $trimmed = trim((string) $raw);
        if ($trimmed === '') {
            return null;
        }

        $digits = preg_replace('/\D/', '', $trimmed);

        // A bare 9-digit mobile written without the trunk "0" is still DZ.
        if (strlen($digits) === 9 && ! str_starts_with($digits, '0')) {
            $digits = '0'.$digits;
        }

        return self::format($digits) ?? $trimmed;
    }
}
