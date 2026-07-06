<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Events;

use App\Modules\Inventory\Models\Box;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A new parking / storage box was added to inventory. Collaboration listens
 * (AnnounceNewBox) to drop a durable "new box added" bell for the whole team and
 * fire the live toast. Kept in Inventory so the module stays Collaboration-free.
 */
class BoxPublished
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public Box $box) {}
}
