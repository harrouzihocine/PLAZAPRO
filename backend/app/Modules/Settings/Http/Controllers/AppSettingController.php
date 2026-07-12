<?php

declare(strict_types=1);

namespace App\Modules\Settings\Http\Controllers;

use App\Modules\Settings\Models\AppSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Scalar app-wide settings. Read is open to any authenticated user (the UI
 * shows e.g. the hold duration); writes require settings.manage (route-gated)
 * and only known keys are accepted — no free-form key creation.
 */
class AppSettingController extends Controller
{
    /** The editable keys and their validation rules. */
    private const KEYS = [
        'interest_hold_hours' => ['required', 'integer', 'min:1', 'max:8760'],
        'reserved_hold_hours' => ['required', 'integer', 'min:1', 'max:8760'],
        // Brute-force lockout: attempts before the account locks, and how long
        // the lock lasts (0 = until an admin unlocks it in Settings → Users).
        'login_max_attempts' => ['required', 'integer', 'min:1', 'max:10'],
        'login_lockout_minutes' => ['required', 'integer', 'min:0', 'max:10080'],
        // Office-visit window: how far ahead an office visit may be planned
        // without a manager's approval (0 = today only, 1 = today or tomorrow).
        'office_visit_max_days' => ['required', 'integer', 'min:0', 'max:60'],
        // The company / office profile: printed into the office-visit invitation
        // an agent sends a client ("come to us"). All optional — a blank field
        // is simply left out of the message. Readable by any authenticated user
        // (agents build the invite); only settings.manage may edit them.
        'company_name' => ['sometimes', 'nullable', 'string', 'max:120'],
        'office_address' => ['sometimes', 'nullable', 'string', 'max:255'],
        'office_maps_url' => ['sometimes', 'nullable', 'url', 'max:500'],
        'office_phone' => ['sometimes', 'nullable', 'string', 'max:40'],
        // The public showcase site (/plaza): contact channels + the "about us"
        // presentation text ({en,fr,ar} written as three separate keys). The
        // WhatsApp number is the site's click-to-chat target — may differ from
        // office_phone. Served to anonymous visitors via GET /public/config.
        'website_whatsapp' => ['sometimes', 'nullable', 'string', 'max:40'],
        'website_email' => ['sometimes', 'nullable', 'email', 'max:120'],
        // Extra contact numbers, one per line (the site lists them all; the
        // first doubles as the legacy singular `phone`).
        'website_phones' => ['sometimes', 'nullable', 'string', 'max:1000'],
        'website_facebook_url' => ['sometimes', 'nullable', 'url', 'max:500'],
        'website_instagram_url' => ['sometimes', 'nullable', 'url', 'max:500'],
        'website_tiktok_url' => ['sometimes', 'nullable', 'url', 'max:500'],
        'website_youtube_url' => ['sometimes', 'nullable', 'url', 'max:500'],
        'website_linkedin_url' => ['sometimes', 'nullable', 'url', 'max:500'],
        'website_x_url' => ['sometimes', 'nullable', 'url', 'max:500'],
        'website_about_en' => ['sometimes', 'nullable', 'string', 'max:5000'],
        'website_about_fr' => ['sometimes', 'nullable', 'string', 'max:5000'],
        'website_about_ar' => ['sometimes', 'nullable', 'string', 'max:5000'],
        // Landing hero (legacy single pick): a media id on a PUBLISHED project —
        // PublicConfigController re-checks everything before emitting URLs, so
        // a stale id degrades to the default cover hero.
        'website_hero_media_id' => ['sometimes', 'nullable', 'integer', 'exists:media,id'],
        // Landing hero, curated set: ordered comma-separated media ids from the
        // hero library (photos → slideshow, a leading video → video hero).
        // Takes precedence over the legacy single id when non-empty.
        'website_hero_media_ids' => ['sometimes', 'nullable', 'string', 'max:400', 'regex:/^\d+(,\d+)*$/'],
        // The dispatch GPS layer: site geofence radius (auto check-in/out),
        // how long an assignment may sit unaccepted / an arrival may run late
        // before the dispatchers are nudged, and breadcrumb retention.
        'dispatch_geofence_radius_m' => ['required', 'integer', 'min:50', 'max:2000'],
        'dispatch_accept_sla_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
        'dispatch_arrival_grace_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
        'agent_position_retention_days' => ['required', 'integer', 'min:7', 'max:365'],
        // Path tracking: duty-snapshot cadence (0 = no path, en-route legs
        // only), the parked-while-available alert, and the en-route drift
        // corridor (0 disables either alert).
        'dispatch_snapshot_minutes' => ['required', 'integer', 'min:0', 'max:30'],
        'dispatch_idle_alert_minutes' => ['required', 'integer', 'min:0', 'max:480'],
        'dispatch_offroute_m' => ['required', 'integer', 'min:0', 'max:20000'],
    ];

    public function index(): JsonResponse
    {
        return response()->json(['data' => AppSetting::remembered()]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(
            collect(self::KEYS)
                ->only(array_keys($request->all()))
                ->whenEmpty(fn () => abort(422, 'No known setting was sent.'))
                ->all(),
        );

        foreach ($data as $key => $value) {
            AppSetting::set($key, (string) $value);
        }

        return response()->json(['data' => AppSetting::remembered()]);
    }
}
