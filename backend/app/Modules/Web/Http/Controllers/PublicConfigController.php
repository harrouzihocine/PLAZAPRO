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

        return response()->json(['data' => [
            'company' => [
                'name' => $value('company_name'),
                'address' => $value('office_address'),
                'phone' => $value('office_phone'),
                'maps_url' => $value('office_maps_url'),
                // The site's click-to-chat number; falls back to the office line.
                'whatsapp' => $value('website_whatsapp') ?? $value('office_phone'),
                'email' => $value('website_email'),
                'facebook_url' => $value('website_facebook_url'),
                'instagram_url' => $value('website_instagram_url'),
            ],
            'about' => [
                'en' => $value('website_about_en'),
                'fr' => $value('website_about_fr'),
                'ar' => $value('website_about_ar'),
            ],
            'stats' => $this->stats(),
            'hero' => $this->hero($value('website_hero_media_id')),
            'form_token' => FormToken::issue(),
        ]]);
    }

    /**
     * The landing hero backdrop the owner picked — a photo or a video, as long
     * as it is publicly streamable (public collection, published project). A
     * stale or private pick silently degrades to the default cover hero —
     * never a dead URL.
     */
    private function hero(?string $mediaId): ?array
    {
        if ($mediaId === null) {
            return null;
        }

        $media = Media::find((int) $mediaId);

        if ($media === null || ! PublicMediaGate::allows($media)) {
            return null;
        }

        $v = $media->updated_at ? '?v='.$media->updated_at->getTimestamp() : '';

        // Relative on purpose — see PublicMediaResource.
        if ($media->type === MediaType::Video) {
            return [
                'type' => 'video',
                'video_url' => route('public.media.file', $media->id, false).$v,
                'poster_url' => $media->thumb_path !== null
                    ? route('public.media.thumb', $media->id, false).$v
                    : null,
            ];
        }

        if ($media->type === MediaType::Photo) {
            return [
                'type' => 'photo',
                'image_url' => route('public.media.file', $media->id, false).$v,
            ];
        }

        return null; // plans (PDFs) pass the gate but make no hero
    }

    /** Headline numbers for the landing hero — published inventory only. */
    private function stats(): array
    {
        $published = Location::query()->published();

        return [
            'projects' => (clone $published)->count(),
            'wilayas' => (clone $published)->whereNotNull('wilaya_id')->distinct()->count('wilaya_id'),
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
