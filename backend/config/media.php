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
];
