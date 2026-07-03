<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Http\Controllers;

use App\Modules\Analytics\Http\Resources\ActivityLogResource;
use App\Modules\Analytics\Models\ActivityLog;
use App\Modules\Analytics\Support\AuditableRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/**
 * Read-only audit trail of ONE record, shown on that record's page. Unlike the
 * admin-only /audit feed, access follows the record's own view permission
 * (someone who can open a unit can read that unit's history), so the map in
 * AuditableRecord is the only gate that matters here.
 */
class RecordActivityController extends Controller
{
    public function index(Request $request, string $type, int $id): AnonymousResourceCollection
    {
        $model = AuditableRecord::modelFor($type);
        abort_if($model === null, 404);

        abort_unless($request->user()->can(AuditableRecord::permissionFor($type)), 403);

        // Cancelled records keep their (append-only) history readable.
        abort_unless($model::query()->whereKey($id)->exists(), 404);

        return ActivityLogResource::collection(
            ActivityLog::query()
                ->with('user:id,name')
                ->where('subject_type', $model)
                ->where('subject_id', $id)
                ->latest('id')
                ->paginate(20)
        );
    }
}
