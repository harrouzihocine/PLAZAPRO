<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Http\Controllers;

use App\Modules\Analytics\Http\Resources\ActivityLogResource;
use App\Modules\Analytics\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/**
 * Read-only admin audit feed over the append-only activity log. No write routes
 * exist. Filterable by user, action, subject type, and date range.
 */
class AuditController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $logs = ActivityLog::query()
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
            ->when($request->filled('action'), fn ($q) => $q->where('action', $request->string('action')))
            ->when($request->filled('subject_type'), fn ($q) => $q->where('subject_type', $request->string('subject_type')))
            ->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->where('created_at', '<=', $request->date('to')))
            ->latest('id')
            ->paginate(50);

        return ActivityLogResource::collection($logs);
    }
}
