<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Enums\MediaCollection;
use App\Modules\Inventory\Rules\SupportedMediaFile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UploadMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('media.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required', 'file',
                // Ceiling from config/media.php (default 200 MB; nginx +
                // php must match). Cap it below Cloudflare's 100 MB edge limit via
                // MEDIA_MAX_UPLOAD_KB when the tunnel is the primary upload path.
                'max:'.(int) config('media.max_upload_kb', 200 * 1024),
                // Content-based type gate (never the extension) — canonical mime
                // via UploadMimeDetector, so real Office files that libmagic can
                // only call "zip"/OLE still resolve. Security baseline unchanged.
                new SupportedMediaFile,
            ],
            // The semantic bucket (tab). Unknown values are rejected — the enum
            // is the single source of truth. Omitted => UploadMedia's default.
            'collection' => ['nullable', Rule::enum(MediaCollection::class)],
        ];
    }
}
