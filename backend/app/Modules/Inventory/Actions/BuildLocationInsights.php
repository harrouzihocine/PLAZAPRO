<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Clients\Models\ClientProject;
use App\Modules\Inventory\Models\Box;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Payments\Models\Versement;

/**
 * A pure read view for the development (Location) detail page: the inventory
 * funnel, the pipeline riding on this site, and the money it has produced.
 *
 * Revenue is only included when the caller is allowed to see money
 * (versements.view / reports.view) — the controller decides, this action obeys.
 */
class BuildLocationInsights
{
    /**
     * @return array<string, mixed>
     */
    public function handle(Location $location, bool $includeRevenue = true): array
    {
        $units = Unit::query()->active()->where('location_id', $location->id);
        $boxes = Box::query()->active()->where('location_id', $location->id);

        $projects = ClientProject::query()->where('location_id', $location->id);

        $data = [
            'units' => [
                'total' => (clone $units)->count(),
                'available' => (clone $units)->where('sale_status', 'available')->count(),
                'reserved' => (clone $units)->where('sale_status', 'reserved')->count(),
                'sold' => (clone $units)->where('sale_status', 'sold')->count(),
            ],
            'boxes' => [
                'total' => (clone $boxes)->count(),
                'available' => (clone $boxes)->where('sale_status', 'available')->count(),
                'sold' => (clone $boxes)->where('sale_status', 'sold')->count(),
            ],
            'pipeline' => [
                'active_projects' => (clone $projects)->active()->count(),
                'won' => (clone $projects)->where('stage', 'won')->count(),
                'lost' => (clone $projects)->where('stage', 'lost')->count(),
            ],
        ];

        if ($includeRevenue) {
            $collected = Versement::query()->active()
                ->whereIn('client_project_id', (clone $projects)->pluck('id'))
                ->sum('amount');

            $soldValue = (clone $units)->where('sale_status', 'sold')->sum('price');

            $data['revenue'] = [
                'collected' => $this->money($collected),
                'sold_value' => $this->money($soldValue),
            ];
        }

        return $data;
    }

    private function money(mixed $value): string
    {
        return number_format((float) ($value ?: 0), 2, '.', '');
    }
}
