<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\Support;

use Illuminate\Support\Str;

/**
 * Pure transform helpers of the legacy importer (PLAZA_MIGRATION_PLAN.md §4.2).
 * Stateless besides the config-driven price multiplier; every method is safe
 * to call in dry-run.
 *
 * Timezones: both MySQL servers run at +01:00 (= Africa/Algiers, no DST) and
 * the dump was taken with TIME_ZONE='+00:00', so legacy TIMESTAMP columns read
 * back as Algiers wall time already — timestamps are copied verbatim. Bare
 * legacy dates become `<date> 00:00:00` (§0.5).
 */
class Transform
{
    public function __construct(private readonly float $priceMultiplier) {}

    /**
     * Normalize a legacy phone (99.3% look like `tel:+213-540-78-26-88`).
     * DZ numbers land as the app convention `+213 540 78 26 88` (+213 then the
     * 9 significant digits grouped 3-2-2-2; 8-digit landlines grouped 2-2-2-2);
     * foreign numbers keep `+E164`; garbage is passed through with ok=false so
     * the caller can warn. `nsn` is informational only — clients.phone_nsn is a
     * stored generated column (last 9 digits), so the +213 form leaves duplicate
     * matching untouched.
     *
     * @return array{phone: ?string, nsn: ?string, ok: bool, foreign: bool}
     */
    public function phone(?string $raw): array
    {
        $trimmed = trim((string) $raw);
        if ($trimmed === '') {
            return ['phone' => null, 'nsn' => null, 'ok' => true, 'foreign' => false];
        }

        $stripped = preg_replace('/^tel:/i', '', $trimmed);
        $hasPlus = str_contains($stripped, '+');
        $digits = preg_replace('/\D+/', '', $stripped);

        if ($digits === '') {
            return ['phone' => $trimmed, 'nsn' => null, 'ok' => false, 'foreign' => false];
        }

        // International DZ prefixes → national 0-form.
        if (str_starts_with($digits, '00213')) {
            $digits = substr($digits, 5);
            $hasPlus = true;
        } elseif (str_starts_with($digits, '213') && strlen($digits) >= 11) {
            $digits = substr($digits, 3);
            $hasPlus = true;
        }

        if (strlen($digits) === 9 && ! str_starts_with($digits, '0')) {
            $digits = '0'.$digits;
        }

        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            // Mobile / 10-digit national → "+213 XXX XX XX XX" (NSN grouped 3-2-2-2).
            $nsn = substr($digits, 1);
            $formatted = '+213 '.substr($nsn, 0, 3).' '.substr($nsn, 3, 2).' '.substr($nsn, 5, 2).' '.substr($nsn, 7, 2);

            return ['phone' => $formatted, 'nsn' => $nsn, 'ok' => true, 'foreign' => false];
        }

        if (str_starts_with($digits, '0') && strlen($digits) === 9) {
            // Landline-length national (0 + 8 digits) → "+213 XX XX XX XX" (2-2-2-2).
            $nsn = substr($digits, 1);
            $formatted = '+213 '.substr($nsn, 0, 2).' '.substr($nsn, 2, 2).' '.substr($nsn, 4, 2).' '.substr($nsn, 6, 2);

            return ['phone' => $formatted, 'nsn' => $nsn, 'ok' => true, 'foreign' => false];
        }

        if ($hasPlus) {
            // Non-DZ number: keep raw +E164 (§4.2).
            return ['phone' => '+'.ltrim(preg_replace('/\D+/', '', $stripped), '0'), 'nsn' => null, 'ok' => true, 'foreign' => true];
        }

        return ['phone' => $trimmed, 'nsn' => null, 'ok' => false, 'foreign' => false];
    }

    /** Legacy "millions de centimes" → DZD (§3: 850 → 8,500,000.00). */
    public function priceDa(float|int|string|null $x): ?float
    {
        if ($x === null || $x === '') {
            return null;
        }

        return round((float) $x * $this->priceMultiplier, 2);
    }

    /**
     * First token → first_name, remainder → last_name (§4.2). `lossy` flags
     * 3+ tokens or a single token — the caller then preserves the original
     * in notes.
     *
     * @return array{first: ?string, last: ?string, lossy: bool}
     */
    public function splitName(?string $name): array
    {
        $clean = trim(preg_replace('/\s+/u', ' ', (string) $name));
        if ($clean === '') {
            return ['first' => null, 'last' => null, 'lossy' => true];
        }
        $tokens = explode(' ', $clean);

        return [
            'first' => $tokens[0],
            'last' => count($tokens) > 1 ? implode(' ', array_slice($tokens, 1)) : null,
            'lossy' => count($tokens) !== 2,
        ];
    }

    /** Bare legacy date → `Y-m-d 00:00:00` Algiers wall time; NULL-safe. */
    public function ts(?string $date): ?string
    {
        $date = (string) $date;
        if ($date === '' || str_starts_with($date, '0000-00-00')) {
            return null;
        }

        return substr($date, 0, 10).' 00:00:00';
    }

    /** Legacy TIMESTAMP/DATETIME → target string; zero-dates become NULL. */
    public function legacyTs(?string $timestamp): ?string
    {
        $timestamp = (string) $timestamp;
        if ($timestamp === '' || str_starts_with($timestamp, '0000-00-00')) {
            return null;
        }

        return $timestamp;
    }

    /**
     * Displaced-facts block (§4.2): non-empty `label: value` lines under a
     * `— Legacy #<id> —` header. Returns NULL when nothing survives.
     *
     * @param  array<string, string|null>  $lines  label → value
     */
    public function noteBlock(int|string $legacyId, array $lines): ?string
    {
        $kept = [];
        foreach ($lines as $label => $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                $kept[] = "{$label}: {$value}";
            }
        }
        if ($kept === []) {
            return null;
        }

        return "— Legacy #{$legacyId} —\n".implode("\n", $kept);
    }

    /** ASCII slug (Arabic transliterated), matching the app's item values. */
    public function slug(string $label, string $separator = '_'): string
    {
        $slug = Str::slug(Str::ascii($label), $separator);

        return $slug !== '' ? $slug : 'legacy'.$separator.'item';
    }
}
