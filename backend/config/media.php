<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Max media upload size (kilobytes)
    |--------------------------------------------------------------------------
    |
    | Ceiling for uploaded photos/videos/PPTX (UploadMediaRequest / ReplaceMedia).
    | Default 200 MB, matching php upload_max_filesize (docker/php/uploads.ini)
    | and nginx client_max_body_size (docker/nginx/prod.conf).
    |
    | IMPORTANT — Cloudflare edge cap: public uploads arrive through the
    | Cloudflare Tunnel, and Cloudflare's FREE plan rejects any request body over
    | 100 MB at the edge (HTTP 413) before it ever reaches the origin. If the
    | tunnel is the primary upload path on the Free plan, set MEDIA_MAX_UPLOAD_KB
    | to ~95000 (95 MB) so the app rejects oversize files with a clear validation
    | error instead of an opaque edge 413 — or adopt chunked uploads / a paid plan
    | for large video.
    |
    */

    'max_upload_kb' => (int) env('MEDIA_MAX_UPLOAD_KB', 200 * 1024),

    /*
    |--------------------------------------------------------------------------
    | Media optimization pipeline
    |--------------------------------------------------------------------------
    |
    | Uploaded photos and videos are re-encoded asynchronously (OptimizeMedia on
    | the `media` queue) into visually-lossless, much smaller files that REPLACE
    | the original bytes — owner decision 2026-07-09: storage savings beat
    | keeping raw phone uploads. A grid thumbnail (photos) / poster frame
    | (videos) is generated alongside for fast gallery loads.
    |
    | Photos  → WebP via vipsthumbnail (libvips: fast, low-memory, auto-rotates
    |           and strips EXIF/GPS). Longest edge capped; quality tuned to be
    |           indistinguishable for property photography.
    | Videos  → H.264 High + AAC in faststart MP4 via ffmpeg, longest edge
    |           capped at 1920 ("1080p-class": landscape 1920×1080, portrait
    |           1080×1920). CRF encode ≈ constant visual quality. Sources that
    |           are already efficient H.264 MP4s are only remuxed (+faststart).
    |
    | The swap is guarded: if the re-encode comes out LARGER than the original
    | (already-optimized source), the original bytes are kept. On any failure
    | the original is untouched and still served — optimization is best-effort.
    |
    */

    'optimize' => [
        'enabled' => (bool) env('MEDIA_OPTIMIZE', true),

        'image' => [
            'max_edge' => (int) env('MEDIA_IMAGE_MAX_EDGE', 2560),
            'quality' => (int) env('MEDIA_IMAGE_QUALITY', 82),
            'thumb_edge' => 480,
            'thumb_quality' => 72,
            // Chat images: single in-place re-encode (bubbles + lightbox share it).
            'chat_max_edge' => 1600,
            'chat_quality' => 80,
        ],

        'video' => [
            'max_edge' => (int) env('MEDIA_VIDEO_MAX_EDGE', 1920),
            'crf' => (int) env('MEDIA_VIDEO_CRF', 23),
            'preset' => env('MEDIA_VIDEO_PRESET', 'medium'),
            'audio_bitrate' => '128k',
            // A source already at/below this bitrate (bps), H.264, MP4, within
            // the size cap is remuxed instead of re-encoded (no generation loss).
            'copy_bitrate_threshold' => (int) env('MEDIA_VIDEO_COPY_BPS', 4_000_000),
            'poster_edge' => 640,
            'poster_quality' => 75,
        ],

        'bins' => [
            'vipsthumbnail' => env('VIPSTHUMBNAIL_BIN', 'vipsthumbnail'),
            'ffmpeg' => env('FFMPEG_BIN', 'ffmpeg'),
            'ffprobe' => env('FFPROBE_BIN', 'ffprobe'),
        ],
    ],
];
