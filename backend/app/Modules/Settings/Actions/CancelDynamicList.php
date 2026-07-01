<?php

declare(strict_types=1);

namespace App\Modules\Settings\Actions;

use App\Modules\Settings\Models\DynamicList;

/**
 * Cancel (no-delete) a dynamic list. System lists are protected and can never
 * be removed by admins.
 */
class CancelDynamicList
{
    public function handle(DynamicList $list, string $reason): DynamicList
    {
        abort_if($list->is_system, 422, 'System lists cannot be removed.');

        return $list->cancel($reason);
    }
}
