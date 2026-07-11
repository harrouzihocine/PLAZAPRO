<?php

declare(strict_types=1);

namespace App\Modules\Web\Support;

use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Media;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Web\Http\Controllers\PublicProjectController;

/**
 * The single answer to "may an anonymous visitor see this media?" — used by
 * the streaming endpoints (as a 404 gate) and by config/hero emitters (to
 * never hand out URLs that would 404). Rule: active media, in a public
 * collection (photos/videos/plans), owned by a PUBLISHED live project,
 * directly or through one of its units.
 */
final class PublicMediaGate
{
    public static function allows(?Media $media): bool
    {
        if ($media === null || ! $media->isActive()) {
            return false;
        }

        if (! in_array($media->collection, PublicProjectController::PUBLIC_COLLECTIONS, true)) {
            return false;
        }

        $mediable = $media->mediable;

        $location = match (true) {
            $mediable instanceof Location => $mediable,
            $mediable instanceof Unit => $mediable->location,
            default => null,
        };

        return $location !== null && $location->isActive() && $location->is_published;
    }
}
