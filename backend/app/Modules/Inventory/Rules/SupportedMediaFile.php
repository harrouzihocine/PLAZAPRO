<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Rules;

use App\Core\Media\UploadMimeDetector;
use App\Modules\Inventory\Enums\MediaType;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * The media library's type gate. Content-based, never the extension: the
 * canonical mime comes from UploadMimeDetector (libmagic + a look inside
 * opaque zip/OLE containers, which real Office files routinely sniff as) and
 * must map to a MediaType. Executables and arbitrary archives stay out.
 */
class SupportedMediaFile implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile
            || MediaType::fromMime(app(UploadMimeDetector::class)->detect($value)) === null) {
            $fail('The :attribute must be a photo, video, PDF, or an Office document (PowerPoint, Word, Excel, OpenDocument).');
        }
    }
}
