<?php

declare(strict_types=1);

namespace App\Modules\Web\Http\Resources;

use App\Modules\Inventory\Enums\MediaType;
use App\Modules\Inventory\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A media asset as the anonymous visitor sees it — URLs point at the public
 * streaming endpoints (which re-check the owning project is published) and
 * nothing about storage (disk, path, uploader, original name) leaves.
 *
 * @mixin Media
 */
class PublicMediaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Same cache-buster convention as the CRM's MediaResource: optimization
        // swaps the bytes behind a stable id, the ?v= makes clients re-fetch.
        $v = $this->updated_at ? '?v='.$this->updated_at->getTimestamp() : '';

        return [
            'id' => $this->id,
            'collection' => $this->collection?->value,
            'type' => $this->type?->value,
            'width' => $this->width,
            'height' => $this->height,
            'duration_seconds' => $this->duration_seconds,
            // RELATIVE on purpose (like the CRM's client-built media URLs): the
            // site is served through proxies (vite dev/preview, future domain)
            // where an absolute backend host would be wrong. The SEO layer
            // absolutizes against window.location.origin where OG tags need it.
            'file_url' => route('public.media.file', $this->id, false).$v,
            // Photos always resolve (endpoint falls back to the original until
            // optimized); videos only once their poster exists.
            'thumb_url' => $this->type === MediaType::Photo || $this->thumb_path !== null
                ? route('public.media.thumb', $this->id, false).$v
                : null,
        ];
    }
}
