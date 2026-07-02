<?php

declare(strict_types=1);

namespace App\Modules\Settings\Http\Controllers;

use App\Modules\Settings\Actions\CancelWilaya;
use App\Modules\Settings\Actions\CreateWilaya;
use App\Modules\Settings\Actions\UpdateWilaya;
use App\Modules\Settings\Http\Requests\StoreWilayaRequest;
use App\Modules\Settings\Http\Requests\UpdateWilayaRequest;
use App\Modules\Settings\Http\Resources\WilayaResource;
use App\Modules\Settings\Models\Wilaya;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/**
 * Wilayas (Algeria's provinces) — reference data any authenticated user can read
 * (it feeds the geographic dropdowns); writes require settings.manage.
 */
class WilayaController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $wilayas = Wilaya::query()
            ->withCount('communes')
            ->when($request->query('status') !== 'all', fn ($q) => $q->active())
            ->orderBy('code')
            ->get();

        return WilayaResource::collection($wilayas);
    }

    public function store(StoreWilayaRequest $request, CreateWilaya $action): WilayaResource
    {
        return new WilayaResource($action->handle($request->validated()));
    }

    public function update(UpdateWilayaRequest $request, Wilaya $wilaya, UpdateWilaya $action): WilayaResource
    {
        return new WilayaResource($action->handle($wilaya, $request->validated()));
    }

    public function destroy(Request $request, Wilaya $wilaya, CancelWilaya $action): WilayaResource
    {
        $reason = (string) $request->input('reason', 'Removed by admin');

        return new WilayaResource($action->handle($wilaya, $reason));
    }
}
