<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Events;

use App\Modules\Inventory\Models\Box;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A parking / storage box's details were edited (reference, type, price, status,
 * or its linked apartment). Collaboration listens (AnnounceBoxEdited) to drop a
 * durable "box updated" bell for the whole team and fire the live toast.
 * $changed is the list of changed attribute keys so the notification can name
 * what moved. Kept in Inventory so the module stays Collaboration-free.
 */
class BoxEdited
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  list<string>  $changed  changed attribute keys (e.g. price, unit_id)
     */
    public function __construct(public Box $box, public array $changed = []) {}
}
