<?php

declare(strict_types=1);

namespace App\Modules\Web\Http\Controllers;

use App\Modules\Inventory\Enums\MediaType;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Media;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Inventory\Support\StreamsMediaFiles;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;

/**
 * Unauthenticated media streaming for the showcase. A file is served only when
 * it sits in a public collection (photos/videos/plans) on a PUBLISHED project
 * — directly or through one of its units. Everything else 404s (never 403:
 * unpublished content must be indistinguishable from nonexistent).
 */
class PublicMediaController extends Controller
{
    use StreamsMediaFiles;

    public function file(Media $media): Response
    {
        $this->authorizePublic($media);

        return $this->stream($media->disk, $media->path, $media->mime_type, $media->original_name);
    }

    /** The grid derivative (WebP thumb / video poster), long-lived cacheable. */
    public function thumb(Media $media): Response
    {
        $this->authorizePublic($media);

        $response = $media->thumb_path !== null
            ? $this->stream($media->disk, $media->thumb_path, 'image/webp', 'thumb.webp')
            : $this->photoFallback($media);

        // Immutable derivative behind a ?v= cache-buster: let browsers (and the
        // SW's cache-first media rule) keep it for a day without revalidating.
        $response->headers->set('Cache-Control', 'public, max-age=86400');

        return $response;
    }

    /** Photos not yet optimized fall back to the original; videos 404. */
    private function photoFallback(Media $media): Response
    {
        abort_unless($media->type === MediaType::Photo, 404);

        return $this->stream($media->disk, $media->path, $media->mime_type, $media->original_name);
    }

    private function authorizePublic(Media $media): void
    {
        abort_unless($media->isActive(), 404);
        abort_unless(in_array($media->collection, PublicProjectController::PUBLIC_COLLECTIONS, true), 404);

        $mediable = $media->mediable;

        $location = match (true) {
            $mediable instanceof Location => $mediable,
            $mediable instanceof Unit => $mediable->location,
            default => null,
        };

        abort_unless(
            $location !== null && $location->isActive() && $location->is_published,
            404,
        );
    }
}
