<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Http\Controllers;

use App\Modules\Analytics\Http\Resources\ActivityLogResource;
use App\Modules\Analytics\Models\ActivityLog;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Read-only admin audit feed over the append-only activity log. The only write
 * in this module is the `export` entry the export itself records (required by the
 * spec) — no domain data is ever mutated. Filterable by user, action, subject
 * type, and date range.
 */
class AuditController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return ActivityLogResource::collection(
            $this->filtered($request)->latest('id')->paginate(50)
        );
    }

    /**
     * Stream the filtered log as CSV. Recording the export in the audit trail is
     * a requirement: an export is itself an auditable action. We log it (with the
     * filters and row count) before streaming so the row exists even if the client
     * aborts mid-download.
     */
    public function export(Request $request): StreamedResponse
    {
        $query = $this->filtered($request);
        $filters = $request->only(['user_id', 'action', 'subject_type', 'from', 'to']);

        ActivityLog::record('export', null, [
            'export' => 'audit',
            'filters' => array_filter($filters, fn ($v) => $v !== null && $v !== ''),
            'count' => (clone $query)->count(),
        ]);

        $columns = ['id', 'created_at', 'user_id', 'role_at_time', 'action', 'subject_type', 'subject_id', 'ip_address', 'changes'];

        return response()->streamDownload(function () use ($query, $columns) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $columns);

            $query->latest('id')->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $row) {
                    fputcsv($out, array_map($this->csvSafe(...), [
                        $row->id,
                        $row->created_at?->toIso8601String(),
                        $row->user_id,
                        $row->role_at_time,
                        $row->action,
                        $row->subject_type,
                        $row->subject_id,
                        $row->ip_address,
                        $row->changes !== null ? json_encode($row->changes) : '',
                    ]));
                }
            });

            fclose($out);
        }, 'audit-'.now()->format('Ymd-His').'.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * Neutralise CSV formula injection: a cell that starts with =, +, -, or @ is
     * treated as a formula by spreadsheet apps. Prefix such values with a quote so
     * stored audit data can never execute when the export is opened.
     */
    private function csvSafe(mixed $value): mixed
    {
        if (is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) {
            return "'".$value;
        }

        return $value;
    }

    /** The shared, read-only filter used by both index and export. */
    private function filtered(Request $request): Builder
    {
        return ActivityLog::query()
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
            ->when($request->filled('action'), fn ($q) => $q->where('action', $request->string('action')))
            ->when($request->filled('subject_type'), fn ($q) => $q->where('subject_type', $request->string('subject_type')))
            ->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->where('created_at', '<=', $request->date('to')));
    }
}
