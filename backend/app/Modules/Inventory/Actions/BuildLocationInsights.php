<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Clients\Models\ClientProject;
use App\Modules\Inventory\Enums\SaleStatus;
use App\Modules\Inventory\Models\Box;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Payments\Models\Versement;
use Illuminate\Support\Facades\DB;

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
                'available' => (clone $units)->where('sale_status', SaleStatus::Available->value)->count(),
                'interested' => (clone $units)->where('sale_status', SaleStatus::Interested->value)->count(),
                'sold' => (clone $units)->where('sale_status', SaleStatus::Sold->value)->count(),
            ],
            'boxes' => [
                'total' => (clone $boxes)->count(),
                'available' => (clone $boxes)->where('sale_status', SaleStatus::Available->value)->count(),
                'sold' => (clone $boxes)->where('sale_status', SaleStatus::Sold->value)->count(),
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

            // What the units actually sold for: the won deal item's agreed
            // price. Units sold without a deal (legacy imports) fall back to
            // the default list price — a fini sale would otherwise be
            // understated by the semi-fini COALESCE.
            $soldValue = (clone $units)->where('sale_status', 'sold')
                ->leftJoin(DB::raw("(select unit_id, max(agreed_price) as agreed_price from deal_items where status = 'active' and state = 'won' group by unit_id) as won_items"), 'won_items.unit_id', '=', 'units.id')
                ->sum(DB::raw('COALESCE(won_items.agreed_price, units.price_semi_fini, units.price_fini)'));

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
