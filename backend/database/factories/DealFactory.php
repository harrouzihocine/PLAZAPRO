<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Clients\Enums\DealState;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Clients\Models\Deal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Deal>
 */
class DealFactory extends Factory
{
    protected $model = Deal::class;

    public function definition(): array
    {
        return [
            'client_project_id' => ClientProject::factory(),
            'visit_id' => null,
            'state' => DealState::Reserved->value,
            'total_price' => null,
            'notes' => null,
            'created_by' => null,
        ];
    }
}
