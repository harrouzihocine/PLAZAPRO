<?php

declare(strict_types=1);

namespace App\Modules\Web\Http\Controllers;

use App\Core\Enums\RecordStatus;
use App\Modules\Inventory\Enums\MediaType;
use App\Modules\Inventory\Enums\SaleStatus;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Media;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Settings\Models\AppSetting;
use App\Modules\Web\Support\FormToken;
use App\Modules\Web\Support\PublicMediaGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;

/**
 * Boot payload for the public showcase: company profile, the trilingual
 * "about us" text, headline stats and the lead-form token. Anonymous by
 * design — only the whitelisted settings below ever leave the building.
 */
class PublicConfigController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $settings = AppSetting::remembered();
        $value = fn (string $key) => ($settings[$key] ?? '') !== '' ? $settings[$key] : null;

        $phones = $this->phones($value('website_phones'), $value('office_phone'));

        return response()->json(['data' => [
            'company' => [
                'name' => $value('company_name'),
                'address' => $value('office_address'),
                // First number kept as `phone` for anything still reading the
                // singular; the site renders the full list.
                'phone' => $phones[0] ?? null,
                'phones' => $phones,
                'maps_url' => $value('office_maps_url'),
                // The site's click-to-chat number; falls back to the office line.
                'whatsapp' => $value('website_whatsapp') ?? $value('office_phone'),
                'email' => $value('website_email'),
                'facebook_url' => $value('website_facebook_url'),
                'instagram_url' => $value('website_instagram_url'),
                'tiktok_url' => $value('website_tiktok_url'),
                'youtube_url' => $value('website_youtube_url'),
                'linkedin_url' => $value('website_linkedin_url'),
                'x_url' => $value('website_x_url'),
            ],
            'about' => [
                'en' => $value('website_about_en'),
                'fr' => $value('website_about_fr'),
                'ar' => $value('website_about_ar'),
            ],
            'stats' => $this->stats(),
            'hero' => $this->hero($value('website_hero_media_ids'), $value('website_hero_media_id')),
            'form_token' => FormToken::issue(),
        ]]);
    }

    /** One number per line in the setting → a clean list for the site. */
    private function phones(?string $raw, ?string $fallback): array
    {
        $phones = collect(preg_split('/[\n,]+/', $raw ?? ''))
            ->map(fn ($p) => trim($p))
            ->filter()
            ->values();

        if ($phones->isEmpty() && $fallback !== null) {
            $phones = collect([$fallback]);
        }

        return $phones->all();
    }

    /**
     * The landing hero the owner curated in Settings → Website: an ordered set
     * of media ids from the hero library (or any published project). A single
     * video makes a video hero; photos make a slideshow. Every id is re-checked
     * against PublicMediaGate so a stale or private pick silently degrades —
     * never a dead URL. The legacy single-id setting still works as fallback.
     */
    private function hero(?string $ids, ?string $legacyId): ?array
    {
        $picked = collect(explode(',', $ids ?? ''))
            ->map(fn ($id) => (int) trim($id))
            ->filter()
            ->take(12);

        if ($picked->isEmpty() && $legacyId !== null) {
            $picked = collect([(int) $legacyId]);
        }

        if ($picked->isEmpty()) {
            return null;
        }

        // Eager mediable: the gate walks it per item (lazy loads throw in dev).
        $media = Media::query()->with('mediable')->whereIn('id', $picked)->get()->keyBy('id');

        /** @var Collection<int, Media> $allowed ordered as the owner picked */
        $allowed = $picked
            ->map(fn (int $id) => $media[$id] ?? null)
            ->filter(fn (?Media $m) => PublicMediaGate::allows($m)
                && in_array($m->type, [MediaType::Photo, MediaType::Video], true))
            ->values();

        if ($allowed->isEmpty()) {
            return null;
        }

        // A leading video is THE hero; otherwise the photos run as a slideshow.
        $first = $allowed->first();

        if ($first->type === MediaType::Video) {
            $v = $first->updated_at ? '?v='.$first->updated_at->getTimestamp() : '';

            // Relative on purpose — see PublicMediaResource.
            return [
                'type' => 'video',
                'video_url' => route('public.media.file', $first->id, false).$v,
                'poster_url' => $first->thumb_path !== null
                    ? route('public.media.thumb', $first->id, false).$v
                    : null,
            ];
        }

        $slides = $allowed
            ->filter(fn (Media $m) => $m->type === MediaType::Photo)
            ->map(function (Media $m) {
                $v = $m->updated_at ? '?v='.$m->updated_at->getTimestamp() : '';

                return ['image_url' => route('public.media.file', $m->id, false).$v];
            })
            ->values();

        if ($slides->count() === 1) {
            return ['type' => 'photo', 'image_url' => $slides[0]['image_url']];
        }

        return ['type' => 'slideshow', 'slides' => $slides->all()];
    }

    /** Headline numbers for the landing hero — published inventory only. */
    private function stats(): array
    {
        $published = Location::query()->published();

        return [
            'projects' => (clone $published)->count(),
            'wilayas' => (clone $published)->whereNotNull('wilaya_id')->distinct()->count('wilaya_id'),
            'communes' => (clone $published)->whereNotNull('commune_id')->distinct()->count('commune_id'),
            // Only projects that chose to show availability contribute, so the
            // public number never reveals more than the project pages do.
            'available_units' => Unit::query()->active()
                ->where('sale_status', SaleStatus::Available->value)
                ->whereHas('location', fn ($q) => $q
                    ->where('status', RecordStatus::Active->value)
                    ->where('is_published', true)
                    ->where('show_availability', true))
                ->count(),
        ];
    }
}
