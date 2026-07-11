<?php

declare(strict_types=1);

namespace App\Modules\Web\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * The public lead form's min-fill-time token. Every public GET hands out an
 * encrypted issue-timestamp; the lead POST refuses tokens younger than a few
 * seconds (a human reads the form first — a bot posts instantly) or older
 * than a day. Encrypted with APP_KEY: no session, no DB, not forgeable.
 */
final class FormToken
{
    private const MIN_SECONDS = 5;

    private const MAX_SECONDS = 86400;

    public static function issue(): string
    {
        return Crypt::encryptString((string) now()->timestamp);
    }

    public static function passes(?string $token): bool
    {
        if ($token === null || $token === '') {
            return false;
        }

        try {
            $issuedAt = (int) Crypt::decryptString($token);
        } catch (DecryptException) {
            return false;
        }

        $age = now()->timestamp - $issuedAt;

        return $age >= self::MIN_SECONDS && $age <= self::MAX_SECONDS;
    }
}
