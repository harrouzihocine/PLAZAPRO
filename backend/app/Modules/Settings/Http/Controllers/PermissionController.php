<?php

declare(strict_types=1);

namespace App\Modules\Settings\Http\Controllers;

use App\Modules\Settings\Http\Resources\PermissionResource;
use App\Modules\Settings\Models\Permission;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/**
 * Read-only catalogue of RBAC permissions, grouped for the role permission
 * matrix. Permissions are seeded config, so there are no write endpoints.
 */
class PermissionController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return PermissionResource::collection(
            Permission::query()->orderBy('group')->orderBy('name')->get()
        );
    }
}
