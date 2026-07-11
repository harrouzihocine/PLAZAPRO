<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Web\Enums\WebLeadStatus;
use App\Modules\Web\Enums\WebLeadType;
use App\Modules\Web\Models\WebLead;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebLead>
 */
class WebLeadFactory extends Factory
{
    protected $model = WebLead::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone' => '05'.fake()->unique()->numerify('########'),
            'message' => fake()->optional()->sentence(),
            'type' => WebLeadType::Interest->value,
            'lead_status' => WebLeadStatus::New->value,
            'locale' => 'fr',
        ];
    }
}
