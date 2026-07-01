<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\Media;

/**
 * "Remove" media: cancel the row (no-delete). The stored file is intentionally
 * kept — media is never physically deleted.
 */
class CancelMedia
{
    public function handle(Media $media, string $reason): Media
    {
        return $media->cancel($reason);
    }
}
