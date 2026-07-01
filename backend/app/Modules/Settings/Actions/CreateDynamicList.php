<?php

declare(strict_types=1);

namespace App\Modules\Settings\Actions;

use App\Modules\Settings\Models\DynamicList;

/**
 * Create an admin-defined dynamic list. System lists (is_system) are created
 * only by the seeder, never through the API.
 */
class CreateDynamicList
{
    public function handle(array $data): DynamicList
    {
        return DynamicList::create([
            'key' => $data['key'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'is_system' => false,
        ]);
    }
}
