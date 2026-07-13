<?php

declare(strict_types=1);

namespace App\Modules\Web\Http\Controllers;

use App\Modules\Inventory\Enums\MediaType;
use App\Modules\Inventory\Models\Media;
use App\Modules\Inventory\Support\StreamsMediaFiles;
use App\Modules\Web\Models\MediaShare;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;

/**
 * The anonymous side of a media share: the client taps the WhatsApp link and
 * lands on /plaza/share/{token}. The token IS the authorization — the page
 * and its streaming serve exactly the share's items (share->allows), live
 * project publication state notwithstanding. Unknown and expired tokens 404
 * alike (an expired link must be indistinguishable from a wrong one), and
 * items that were cancelled since sending silently drop out.
 */
class PublicShareController extends Controller
{
    use StreamsMediaFiles;

    public function show(string $token): JsonResponse
    {
        $share = $this->liveShare($token);

        // The pivot keeps gallery order; collection rules mirror allows().
        $media = $share->media()
            ->active()
            ->whereIn('collection', array_map(
                fn ($c) => $c->value,
                PublicProjectController::PUBLIC_COLLECTIONS,
            ))
            ->get();

        return response()->json(['data' => [
            'title' => $share->title,
            'expires_at' => $share->expires_at,
            'media' => $media->map(fn (Media $m) => $this->mediaPayload($share, $m))->values(),
        ]]);
    }

    public function file(string $token, Media $media): Response
    {
        $this->authorizeShare($token, $media);

        return $this->stream($media->disk, $media->path, $media->mime_type, $media->original_name);
    }

    /** The grid derivative — same fallback + caching as the showcase's thumb. */
    public function thumb(string $token, Media $media): Response
    {
        $this->authorizeShare($token, $media);

        if ($media->thumb_path !== null) {
            $response = $this->stream($media->disk, $media->thumb_path, 'image/webp', 'thumb.webp');
        } else {
            abort_unless($media->type === MediaType::Photo, 404);
            $response = $this->stream($media->disk, $media->path, $media->mime_type, $media->original_name);
        }

        $response->headers->set('Cache-Control', 'public, max-age=86400');

        return $response;
    }

    /**
     * Same field set as PublicMediaResource (nothing about storage leaves),
     * with URLs on the share's own tokened streaming routes.
     */
    private function mediaPayload(MediaShare $share, Media $media): array
    {
        $params = ['token' => $share->token, 'media' => $media->id];
        $v = $media->updated_at ? '?v='.$media->updated_at->getTimestamp() : '';

        return [
            'id' => $media->id,
            'collection' => $media->collection?->value,
            'type' => $media->type?->value,
            'width' => $media->width,
            'height' => $media->height,
            'duration_seconds' => $media->duration_seconds,
            // Relative like every public media URL (proxies, future domain).
            'file_url' => route('public.share.file', $params, false).$v,
            'thumb_url' => $media->type === MediaType::Photo || $media->thumb_path !== null
                ? route('public.share.thumb', $params, false).$v
                : null,
        ];
    }

    private function liveShare(string $token): MediaShare
    {
        return MediaShare::query()->live()->where('token', $token)->firstOrFail();
    }

    private function authorizeShare(string $token, Media $media): void
    {
        abort_unless($this->liveShare($token)->allows($media), 404);
    }
}
