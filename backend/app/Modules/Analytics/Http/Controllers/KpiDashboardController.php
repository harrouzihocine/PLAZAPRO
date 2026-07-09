<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Http\Controllers;

use App\Modules\Analytics\Actions\BuildAgentKpis;
use App\Modules\Analytics\Actions\BuildCancellationKpis;
use App\Modules\Analytics\Actions\BuildCollectionsKpis;
use App\Modules\Analytics\Actions\BuildHoldKpis;
use App\Modules\Analytics\Actions\BuildInventoryKpis;
use App\Modules\Analytics\Actions\BuildKpiOverview;
use App\Modules\Analytics\Actions\BuildPipelineKpis;
use App\Modules\Analytics\Actions\BuildProfitabilityKpis;
use App\Modules\Analytics\Actions\BuildSalesKpis;
use App\Modules\Analytics\Actions\BuildKpiTrends;
use App\Modules\Analytics\Models\ProjectCost;
use App\Modules\Analytics\Models\SalesTarget;
use App\Modules\Analytics\Support\ResolveKpiFilters;
use App\Modules\Inventory\Models\Location;
use App\Modules\Settings\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

/**
 * The company-wide KPI command center. Every read method resolves the shared
 * global filters (period + dimensions) then hands off to the section's Build*
 * service — thin by design, all query logic lives in the services. The
 * targets/costs endpoints are the small editable configuration behind the
 * target-attainment and profitability KPIs. Gated by analytics.kpi (routes.php).
 */
class KpiDashboardController extends Controller
{
    public function overview(Request $request, ResolveKpiFilters $filters, BuildKpiOverview $action): JsonResponse
    {
        return $this->respond($action->handle($filters->handle($request)));
    }

    public function sales(Request $request, ResolveKpiFilters $filters, BuildSalesKpis $action): JsonResponse
    {
        return $this->respond($action->handle($filters->handle($request)));
    }

    public function inventory(Request $request, ResolveKpiFilters $filters, BuildInventoryKpis $action): JsonResponse
    {
        return $this->respond($action->handle($filters->handle($request)));
    }

    public function holds(Request $request, ResolveKpiFilters $filters, BuildHoldKpis $action): JsonResponse
    {
        return $this->respond($action->handle($filters->handle($request)));
    }

    public function pipeline(Request $request, ResolveKpiFilters $filters, BuildPipelineKpis $action): JsonResponse
    {
        return $this->respond($action->handle($filters->handle($request)));
    }

    public function collections(Request $request, ResolveKpiFilters $filters, BuildCollectionsKpis $action): JsonResponse
    {
        return $this->respond($action->handle($filters->handle($request)));
    }

    public function agents(Request $request, ResolveKpiFilters $filters, BuildAgentKpis $action): JsonResponse
    {
        return $this->respond($action->handle($filters->handle($request)));
    }

    public function cancellations(Request $request, ResolveKpiFilters $filters, BuildCancellationKpis $action): JsonResponse
    {
        return $this->respond($action->handle($filters->handle($request)));
    }

    public function profitability(Request $request, ResolveKpiFilters $filters, BuildProfitabilityKpis $action): JsonResponse
    {
        return $this->respond($action->handle($filters->handle($request)));
    }

    /**
     * Time-series from kpi_snapshots for the trend charts. Params: metrics
     * (comma list, optional), days (default 90), location_id (optional dimension).
     */
    public function trends(Request $request, BuildKpiTrends $action): JsonResponse
    {
        $metrics = $request->filled('metrics')
            ? array_values(array_filter(explode(',', $request->string('metrics')->toString())))
            : null;

        return $this->respond($action->handle(
            $metrics,
            $request->filled('days') ? $request->integer('days') : 90,
            $request->filled('location_id') ? $request->integer('location_id') : null,
        ));
    }

    /**
     * The reference data the filter bar needs: developments (locations), room
     * types and agents to populate the three dimension selects.
     */
    public function filters(): JsonResponse
    {
        return $this->respond([
            'locations' => Location::query()->orderBy('name')->get(['id', 'name']),
            'unit_types' => \App\Modules\Settings\Models\DynamicListItem::query()
                ->whereHas('list', fn ($q) => $q->where('key', 'room_numbers'))
                ->orderBy('label')->get(['id', 'label']),
            'agents' => User::query()->where('is_active', true)
                ->whereHas('role', fn ($q) => $q->where('is_agent', true))
                ->orderBy('name')->get(['id', 'name']),
        ]);
    }

    // ---- Targets (target-attainment configuration) ---------------------------

    public function targets(): JsonResponse
    {
        return $this->respond([
            'targets' => SalesTarget::query()
                ->orderByDesc('period_start')
                ->get(),
        ]);
    }

    public function saveTarget(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scope' => 'required|in:company,agent,location,unit_type',
            'scope_id' => 'nullable|integer',
            'scope_key' => 'nullable|string|max:50',
            'period_type' => 'required|in:month,quarter,year',
            'period_start' => 'required|date',
            'metric' => 'required|in:sales_value,units_sold,collections',
            'target_amount' => 'required|numeric|min:0',
        ]);

        $target = SalesTarget::updateOrCreate(
            [
                'scope' => $data['scope'],
                'scope_id' => $data['scope_id'] ?? null,
                'scope_key' => $data['scope_key'] ?? null,
                'period_type' => $data['period_type'],
                'period_start' => $data['period_start'],
                'metric' => $data['metric'],
            ],
            ['target_amount' => $data['target_amount'], 'created_by' => Auth::id()],
        );

        return $this->respond($target->toArray());
    }

    // ---- Project (development) costs ----------------------------------------

    public function costs(): JsonResponse
    {
        return $this->respond([
            'costs' => ProjectCost::query()->with('location:id,name')->get(),
            'locations' => Location::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function saveCost(Request $request): JsonResponse
    {
        $data = $request->validate([
            'location_id' => 'required|integer|exists:locations,id',
            'land_cost' => 'required|numeric|min:0',
            'construction_cost' => 'required|numeric|min:0',
            'fees' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:1000',
        ]);

        $cost = ProjectCost::updateOrCreate(
            ['location_id' => $data['location_id']],
            [
                'land_cost' => $data['land_cost'],
                'construction_cost' => $data['construction_cost'],
                'fees' => $data['fees'],
                'notes' => $data['notes'] ?? null,
                'updated_by' => Auth::id(),
            ],
        );

        return $this->respond($cost->load('location:id,name')->toArray());
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function respond(array $data): JsonResponse
    {
        return response()->json(['data' => $data]);
    }
}
