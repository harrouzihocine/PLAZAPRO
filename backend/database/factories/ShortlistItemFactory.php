<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Clients\Enums\ShortlistState;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Clients\Models\ShortlistItem;
use App\Modules\Inventory\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShortlistItem>
 */
class ShortlistItemFactory extends Factory
{
    protected $model = ShortlistItem::class;

    public function definition(): array
    {
        return [
            'client_project_id' => ClientProject::factory(),
            'shortlistable_type' => 'unit',
            'shortlistable_id' => Unit::factory(),
            'state' => ShortlistState::Shortlisted->value,
            'note' => null,
        ];
    }
}
