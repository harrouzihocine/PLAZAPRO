<?php

declare(strict_types=1);

namespace App\Modules\Settings\Http\Controllers;

use App\Modules\Settings\Actions\CancelCommune;
use App\Modules\Settings\Actions\CreateCommune;
use App\Modules\Settings\Actions\UpdateCommune;
use App\Modules\Settings\Http\Requests\StoreCommuneRequest;
use App\Modules\Settings\Http\Requests\UpdateCommuneRequest;
use App\Modules\Settings\Http\Resources\CommuneResource;
use App\Modules\Settings\Models\Commune;
use App\Modules\Settings\Models\Wilaya;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/**
 * Communes of a wilaya — reference data any authenticated user can read (it feeds
 * the dependent commune dropdown); writes require settings.manage.
 */
class CommuneController extends Controller
{
    public function index(Request $request, Wilaya $wilaya): AnonymousResourceCollection
    {
        $communes = $wilaya->communes()
            ->when($request->query('status') !== 'all', fn ($q) => $q->active())
            ->orderBy('name')
            ->get();

        return CommuneResource::collection($communes);
    }

    public function store(StoreCommuneRequest $request, Wilaya $wilaya, CreateCommune $action): CommuneResource
    {
        return new CommuneResource($action->handle($wilaya, $request->validated()));
    }

    public function update(UpdateCommuneRequest $request, Commune $commune, UpdateCommune $action): CommuneResource
    {
        return new CommuneResource($action->handle($commune, $request->validated()));
    }

    public function destroy(Request $request, Commune $commune, CancelCommune $action): CommuneResource
    {
        $reason = (string) $request->input('reason', 'Removed by admin');

        return new CommuneResource($action->handle($commune, $reason));
    }
}
