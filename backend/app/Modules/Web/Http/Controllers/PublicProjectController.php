<?php

declare(strict_types=1);

namespace App\Modules\Web\Http\Controllers;

use App\Core\Enums\RecordStatus;
use App\Modules\Inventory\Enums\MediaCollection;
use App\Modules\Inventory\Enums\SaleStatus;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Web\Http\Resources\PublicProjectResource;
use App\Modules\Web\Http\Resources\PublicProjectSummaryResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/**
 * The showcase's project reads. Everything goes through Location::published()
 * — an unpublished project is indistinguishable from a nonexistent one (404),
 * and the payloads are built field-by-field by the Public* resources so no
 * internal column can leak by accident.
 */
class PublicProjectController extends Controller
{
    /** Media buckets visitors may see. Documents/presentations stay internal. */
    public const PUBLIC_COLLECTIONS = [MediaCollection::Photos, MediaCollection::Videos, MediaCollection::Plans];

    public function index(Request $request): AnonymousResourceCollection
    {
        $locations = Location::query()->published()
            ->with(['wilaya:id,code,name', 'commune:id,wilaya_id,name', 'type', 'coverMedia'])
            ->when($request->filled('wilaya_id'), fn ($q) => $q->where('wilaya_id', $request->query('wilaya_id')))
            ->when($request->filled('type_id'), fn ($q) => $q->where('type_id', $request->query('type_id')))
            ->withCount([
                'units as units_total' => fn ($q) => $q->active(),
                'units as available_count' => fn ($q) => $q->active()
                    ->where('sale_status', SaleStatus::Available->value),
            ])
            // Cheapest purchasable price across both finishes (sold excluded);
            // the resource only exposes it when the project shows prices.
            ->addSelect(['price_from' => Unit::query()
                ->selectRaw('MIN(LEAST(COALESCE(price_semi_fini, price_fini), COALESCE(price_fini, price_semi_fini)))')
                ->whereColumn('units.location_id', 'locations.id')
                ->where('units.status', RecordStatus::Active->value)
                ->where('units.sale_status', '!=', SaleStatus::Sold->value),
            ])
            ->orderBy('name')
            ->get();

        return PublicProjectSummaryResource::collection($locations);
    }

    public function show(int $id): PublicProjectResource
    {
        $location = Location::query()->published()
            ->with(['wilaya:id,code,name', 'commune:id,wilaya_id,name', 'type', 'contractType', 'paymentMethods', 'coverMedia'])
            ->findOrFail($id);

        $location->load([
            'media' => fn ($q) => $q->active()
                ->whereIn('collection', array_map(fn ($c) => $c->value, self::PUBLIC_COLLECTIONS))
                ->orderBy('collection')->orderBy('sort_order'),
        ]);

        if ($location->show_availability) {
            $location->load([
                'units' => fn ($q) => $q->active()
                    ->with(['roomNumber', 'floor'])
                    ->orderBy('block')->orderBy('stack_floor')->orderBy('position')->orderBy('reference'),
            ]);
        }

        return new PublicProjectResource($location);
    }
}
