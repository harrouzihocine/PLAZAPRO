<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Http\Controllers;

use App\Modules\Analytics\Actions\BuildArchive;
use App\Modules\Analytics\Actions\BuildOversight;
use App\Modules\Analytics\Models\ActivityLog;
use App\Modules\Clients\Actions\BuildDesireMatches;
use App\Modules\Clients\Models\ClientDuplicateRequest;
use App\Modules\Settings\Models\UserDraft;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Team-Oversight center: read-only monitors of anomalies / lazy work, plus
 * the one write it needs (clearing a stuck draft). Each endpoint is gated by its
 * own oversight.* permission; the queries are company-wide and accept
 * from / to / user_id filters.
 */
class OversightController extends Controller
{
    public function __construct(
        private BuildOversight $oversight,
        private BuildArchive $buildArchive,
        private BuildDesireMatches $desireMatches,
    ) {}

    public function clients(Request $request): JsonResponse
    {
        $f = $this->filters($request);

        return response()->json(['data' => [
            'empty' => $this->oversight->emptyClients($f),
            'no_name' => $this->oversight->noNameClients($f),
        ]]);
    }

    public function pipeline(Request $request): JsonResponse
    {
        $f = $this->filters($request);

        return response()->json(['data' => [
            'stuck' => $this->oversight->stuckProjects($f),
            'overdue' => $this->oversight->overdueActions($f),
            'upcoming_office_visits' => $this->oversight->upcomingOfficeVisits($f),
        ]]);
    }

    public function deals(Request $request): JsonResponse
    {
        $f = $this->filters($request);

        return response()->json(['data' => [
            'lost_paid' => $this->oversight->lostPaidDeals($f),
            'stale_visits' => $this->oversight->staleVisits($f),
        ]]);
    }

    public function drafts(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->oversight->drafts($this->filters($request))]);
    }

    /**
     * The Archive desk: paginated list of archived (lost/closed) projects across
     * the team, plus a summary strip (total, value, by reason, by opener).
     */
    public function archive(Request $request): JsonResponse
    {
        $result = $this->buildArchive->handle($this->archiveFilters($request));
        $items = $result['items'];

        return response()->json(['data' => [
            'items' => $items->items(),
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
            ],
            'summary' => $result['summary'],
        ]]);
    }

    /**
     * Stream the filtered archive as CSV. The export is itself auditable, so it is
     * logged (with filters + row count) before streaming — the row exists even if
     * the client aborts mid-download. Mirrors AuditController::export.
     */
    public function exportArchive(Request $request): StreamedResponse
    {
        $filters = $this->archiveFilters($request);
        $query = $this->buildArchive->baseQuery($filters);

        ActivityLog::record('export', null, [
            'export' => 'archive',
            'filters' => array_filter($filters, fn ($v) => $v !== null && $v !== ''),
            'count' => (clone $query)->count(),
        ]);

        $columns = ['id', 'client', 'unit', 'location', 'price', 'reason', 'opened_by', 'archived_at'];

        return response()->streamDownload(function () use ($query, $columns) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $columns);

            foreach ($query->with(['client:id,first_name,last_name', 'location:id,name', 'unit:id,reference', 'creator:id,name'])->lazyById() as $p) {
                fputcsv($out, array_map($this->csvSafe(...), [
                    $p->id,
                    $p->client?->full_name,
                    $p->unit?->reference,
                    $p->location?->name,
                    $p->total_price,
                    $p->cancellation_reason,
                    $p->creator?->name,
                    $p->updated_at?->toIso8601String(),
                ]));
            }

            fclose($out);
        }, 'archive-'.now()->format('Ymd-His').'.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }

    /** Bare counts for the sidebar badges — only for monitors the caller may see. */
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        $counts = $this->oversight->counts();

        $out = [];
        if ($user->can('oversight.clients')) {
            $out['clients'] = $counts['clients'];
        }
        if ($user->can('oversight.pipeline')) {
            $out['pipeline'] = $counts['pipeline'];
        }
        if ($user->can('oversight.deals')) {
            $out['deals'] = $counts['deals'];
        }
        if ($user->can('oversight.drafts')) {
            $out['drafts'] = $counts['drafts'];
        }
        if ($user->can('clients.duplicates.resolve')) {
            $out['duplicates'] = ClientDuplicateRequest::query()->where('status', 'pending')->count();
        }
        if ($user->can('oversight.archive')) {
            $out['archive'] = $this->buildArchive->baseQuery([])->count();
        }
        // Same clients.view + units.view gate as GET /desires/matches.
        if ($user->can('clients.view') && $user->can('units.view')) {
            $out['matches'] = $this->desireMatches->count($user->isAgent() ? $user->id : null);
        }

        return response()->json(['data' => $out]);
    }

    /** Clear a user's stuck draft (their browser drops it on next reconcile). */
    public function removeDraft(UserDraft $draft): JsonResponse
    {
        $draft->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * @return array{from: ?string, to: ?string, user_id: ?string}
     */
    private function filters(Request $request): array
    {
        return [
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'user_id' => $request->query('user_id'),
        ];
    }

    /** The richer filter set the Archive desk accepts (search / reason / price …). */
    private function archiveFilters(Request $request): array
    {
        return $request->only([
            'search', 'reason', 'location_id', 'agent_id',
            'from', 'to', 'min_price', 'max_price', 'sort', 'per_page',
        ]);
    }

    /**
     * Neutralise CSV formula injection: a cell starting with =, +, -, or @ is
     * quote-prefixed so stored data can never execute when the export is opened.
     */
    private function csvSafe(mixed $value): mixed
    {
        if (is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) {
            return "'".$value;
        }

        return $value;
    }
}
