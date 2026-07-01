<?php

declare(strict_types=1);

namespace App\Modules\Settings\Http\Controllers;

use App\Modules\Settings\Actions\CancelRole;
use App\Modules\Settings\Actions\CreateRole;
use App\Modules\Settings\Actions\UpdateRole;
use App\Modules\Settings\Http\Requests\StoreRoleRequest;
use App\Modules\Settings\Http\Requests\UpdateRoleRequest;
use App\Modules\Settings\Http\Resources\RoleResource;
use App\Modules\Settings\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/**
 * Roles. `index` is readable by any authenticated user (role/agent pickers rely
 * on it); creating, editing and cancelling roles require roles.manage.
 */
class RoleController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return RoleResource::collection(
            Role::query()->with('permissions')->withCount('users')->orderBy('name')->get()
        );
    }

    public function store(StoreRoleRequest $request, CreateRole $action): RoleResource
    {
        return new RoleResource($action->handle($request->validated())->loadCount('users'));
    }

    public function update(UpdateRoleRequest $request, Role $role, UpdateRole $action): RoleResource
    {
        return new RoleResource($action->handle($role, $request->validated())->loadCount('users'));
    }

    public function destroy(Request $request, Role $role, CancelRole $action): RoleResource
    {
        $reason = (string) $request->input('reason', 'Removed by admin');

        return new RoleResource($action->handle($role, $reason));
    }
}
