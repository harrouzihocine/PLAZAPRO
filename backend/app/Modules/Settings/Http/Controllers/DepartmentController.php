<?php

declare(strict_types=1);

namespace App\Modules\Settings\Http\Controllers;

use App\Modules\Settings\Actions\CancelDepartment;
use App\Modules\Settings\Actions\CreateDepartment;
use App\Modules\Settings\Actions\UpdateDepartment;
use App\Modules\Settings\Http\Requests\StoreDepartmentRequest;
use App\Modules\Settings\Http\Requests\UpdateDepartmentRequest;
use App\Modules\Settings\Http\Resources\DepartmentResource;
use App\Modules\Settings\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/**
 * Departments. `index` is reference data any authenticated user can read (it
 * feeds department pickers elsewhere); writes require settings.manage.
 */
class DepartmentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $departments = Department::query()
            ->withCount('users')
            ->when($request->query('status') !== 'all', fn ($q) => $q->active())
            ->orderBy('name')
            ->get();

        return DepartmentResource::collection($departments);
    }

    public function store(StoreDepartmentRequest $request, CreateDepartment $action): DepartmentResource
    {
        return new DepartmentResource($action->handle($request->validated()));
    }

    public function update(UpdateDepartmentRequest $request, Department $department, UpdateDepartment $action): DepartmentResource
    {
        return new DepartmentResource($action->handle($department, $request->validated()));
    }

    public function destroy(Request $request, Department $department, CancelDepartment $action): DepartmentResource
    {
        $reason = (string) $request->input('reason', 'Removed by admin');

        return new DepartmentResource($action->handle($department, $reason));
    }
}
