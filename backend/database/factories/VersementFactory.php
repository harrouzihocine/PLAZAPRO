<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Clients\Models\ClientProject;
use App\Modules\Payments\Models\Versement;
use App\Modules\Settings\Models\DynamicListItem;
use App\Modules\Settings\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Versement>
 */
class VersementFactory extends Factory
{
    protected $model = Versement::class;

    public function definition(): array
    {
        return [
            'client_project_id' => ClientProject::factory(),
            'amount' => '1000.00',
            'paid_on' => now()->toDateString(),
            'method_id' => DynamicListItem::factory(),
            'reference' => null,
            'schedule_item_id' => null,
            'recorded_by' => User::factory(),
            'document_id' => null,
        ];
    }
}
