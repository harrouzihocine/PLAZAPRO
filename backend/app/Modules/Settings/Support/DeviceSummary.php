<?php

declare(strict_types=1);

namespace App\Modules\Settings\Support;

/**
 * Best-effort user-agent → human summary for the connected-sessions screen
 * ("Chrome · Windows", "PLAZA PRO app · Android"). Deliberately tiny: it only
 * distinguishes the handful of browsers/platforms the team actually uses —
 * anything exotic falls back to null and the UI shows a generic device.
 */
final class DeviceSummary
{
    /** Ordered: Edge/Opera/Samsung UAs also contain "Chrome/", Chrome contains "Safari/". */
    private const BROWSERS = [
        'Edg/' => 'Edge',
        'OPR/' => 'Opera',
        'SamsungBrowser/' => 'Samsung Internet',
        'Firefox/' => 'Firefox',
        'FxiOS/' => 'Firefox',
        'CriOS/' => 'Chrome',
        'Chrome/' => 'Chrome',
        'Safari/' => 'Safari',
    ];

    /** @return array{browser: ?string, platform: ?string, device: ?string, is_app: bool} */
    public static function fromUserAgent(?string $userAgent): array
    {
        $ua = (string) $userAgent;

        // The Capacitor shell appends "PlazaProNative/<version>" to the WebView UA.
        $isApp = str_contains($ua, 'PlazaProNative');

        $platform = match (true) {
            str_contains($ua, 'Windows') => 'Windows',
            // iOS before macOS: iPhone/iPad UAs also say "like Mac OS X".
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') || str_contains($ua, 'iPod') => 'iOS',
            str_contains($ua, 'Macintosh') || str_contains($ua, 'Mac OS X') => 'macOS',
            // Android before Linux: Android UAs also say "Linux".
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'CrOS') => 'ChromeOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => null,
        };

        $device = match (true) {
            str_contains($ua, 'iPad'),
            str_contains($ua, 'Android') && ! str_contains($ua, 'Mobile') => 'tablet',
            str_contains($ua, 'Mobi') || str_contains($ua, 'iPhone') => 'phone',
            $platform === null => null,
            default => 'desktop',
        };

        $browser = null;
        if (! $isApp) {
            foreach (self::BROWSERS as $needle => $name) {
                if (str_contains($ua, $needle)) {
                    $browser = $name;
                    break;
                }
            }
        }

        return ['browser' => $browser, 'platform' => $platform, 'device' => $device, 'is_app' => $isApp];
    }
}
