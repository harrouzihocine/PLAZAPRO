<?php

declare(strict_types=1);

namespace App\Modules\Settings\Actions;

use App\Modules\Settings\Models\DynamicList;
use Illuminate\Support\Arr;

/**
 * Update a dynamic list. The `key` is immutable (application code depends on it)
 * and system lists cannot be renamed.
 */
class UpdateDynamicList
{
    public function handle(DynamicList $list, array $data): DynamicList
    {
        if ($list->is_system && array_key_exists('name', $data) && $data['name'] !== $list->name) {
            abort(422, 'System lists cannot be renamed.');
        }

        $list->update(Arr::only($data, ['name', 'description']));

        return $list->fresh();
    }
}
