<?php

declare(strict_types=1);

namespace App\Modules\Settings\Http\Controllers;

use App\Modules\Settings\Actions\CancelUser;
use App\Modules\Settings\Actions\CreateUser;
use App\Modules\Settings\Actions\SetUserActive;
use App\Modules\Settings\Actions\UpdateUser;
use App\Modules\Settings\Http\Requests\SetUserActiveRequest;
use App\Modules\Settings\Http\Requests\StoreUserRequest;
use App\Modules\Settings\Http\Requests\UpdateUserRequest;
use App\Modules\Settings\Http\Resources\UserResource;
use App\Modules\Settings\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/**
 * Users admin. Every endpoint requires users.manage (see routes). Users are
 * created with exactly one role, deactivated or cancelled — never deleted.
 */
class UserController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $users = User::query()
            ->with(['role', 'department'])
            ->when($request->query('status') !== 'all', fn ($q) => $q->active())
            ->when($request->filled('role_id'), fn ($q) => $q->where('role_id', $request->integer('role_id')))
            ->when($request->filled('department_id'), fn ($q) => $q->where('department_id', $request->integer('department_id')))
            ->when($request->filled('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderBy('name')
            ->get();

        return UserResource::collection($users);
    }

    /**
     * Active agents only — feeds the "assign agent" pickers on clients and visits.
     * Open to any authenticated user (reference data), like the other pickers.
     */
    public function agents(): AnonymousResourceCollection
    {
        $agents = User::query()
            ->with('role')
            ->active()
            ->where('is_active', true)
            ->whereHas('role', fn ($q) => $q->where('is_agent', true))
            ->orderBy('name')
            ->get();

        return UserResource::collection($agents);
    }

    /**
     * Active follow-up agents — users whose role can log calls (calls.log): the
     * sales agents (and managers) who follow a client up, as opposed to the field
     * agents who conduct visits. Feeds the client "assigned agent" picker.
     */
    public function followUpAgents(): AnonymousResourceCollection
    {
        $agents = User::query()
            ->with('role')
            ->active()
            ->where('is_active', true)
            ->whereHas('role.permissions', fn ($q) => $q->where('slug', 'calls.log'))
            ->orderBy('name')
            ->get();

        return UserResource::collection($agents);
    }

    public function store(StoreUserRequest $request, CreateUser $action): UserResource
    {
        return new UserResource($action->handle($request->validated()));
    }

    public function update(UpdateUserRequest $request, User $user, UpdateUser $action): UserResource
    {
        return new UserResource($action->handle($user, $request->validated(), $request->user()));
    }

    public function setActive(SetUserActiveRequest $request, User $user, SetUserActive $action): UserResource
    {
        return new UserResource(
            $action->handle($user, $request->user(), $request->boolean('is_active')),
        );
    }

    public function destroy(Request $request, User $user, CancelUser $action): UserResource
    {
        $reason = (string) $request->input('reason', 'Removed by admin');

        return new UserResource($action->handle($user, $request->user(), $reason));
    }
}
