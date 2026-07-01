<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Clients\Enums\ClientProjectStage;
use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientProject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientProject>
 */
class ClientProjectFactory extends Factory
{
    protected $model = ClientProject::class;

    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'location_id' => null,
            'unit_id' => null,
            'stage' => ClientProjectStage::Lead->value,
            'total_price' => null,
        ];
    }

    public function stage(ClientProjectStage $stage): static
    {
        return $this->state(fn () => ['stage' => $stage->value]);
    }
}
