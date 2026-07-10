<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Http\Controllers;

use App\Modules\Clients\Models\ClientProject;
use App\Modules\Pipeline\Enums\NextActionApproval;
use App\Modules\Pipeline\Enums\NextActionType;
use App\Modules\Pipeline\Models\NextAction;
use App\Modules\Pipeline\Models\Visit;
use App\Modules\Pipeline\Support\OfficeVisitWindow;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * The Office Visits Program: one manager-facing week of office visits laid out
 * by day × hour (the read side of the dedicated page), plus every plan waiting
 * for an approval verdict. Gated by oversight.pipeline — the same audience the
 * upcoming-office-visits oversight list already serves; the decision buttons
 * on the page are additionally gated by visits.dispatch (its own endpoint).
 */
class OfficeProgramController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $start = ($request->filled('week')
            ? Carbon::parse((string) $request->query('week'))
            : now())->startOfWeek();
        $end = $start->copy()->endOfWeek();

        return response()->json(['data' => [
            'week_start' => $start->toDateString(),
            'window_days' => OfficeVisitWindow::maxDays(),
            'visits' => $this->weekVisits($start, $end),
            'pending_approvals' => $this->pendingApprovals(),
        ]]);
    }

    /**
     * The week's office visits. Held ones stay on the grid as history (done);
     * open ones only while their project is still live — the same liveness
     * rule as the oversight upcoming list. A not-yet-approved plan's visit
     * shows as awaiting_approval so the manager sees it inside the program.
     *
     * @return list<array<string, mixed>>
     */
    private function weekVisits(Carbon $start, Carbon $end): array
    {
        return Visit::query()->active()
            ->where('type', 'office')
            ->whereBetween('scheduled_at', [$start, $end])
            ->where(fn (Builder $w) => $w
                ->whereNotNull('completed_at')
                ->orWhereNull('client_project_id')
                ->orWhereHas('clientProject', fn (Builder $p) => $p
                    ->where('status', 'active')->whereNull('frozen_at')))
            ->with([
                'agent:id,name',
                'client:id,first_name,last_name',
                'clientProject:id,client_id',
                'clientProject.client:id,first_name,last_name',
                'nextAction:id,approval_status',
            ])
            ->orderBy('scheduled_at')
            ->get()
            ->map(fn (Visit $v) => [
                'id' => $v->id,
                'client' => (($v->clientProject?->client) ?? $v->client)?->full_name,
                'client_id' => $v->client_id,
                'project_id' => $v->client_project_id,
                'agent' => $v->agent?->name,
                'scheduled_at' => $v->scheduled_at,
                'day' => $v->scheduled_at?->toDateString(),
                'time' => $this->wallClock($v->scheduled_at),
                'state' => $v->completed_at !== null
                    ? 'done'
                    : ($v->nextAction?->approval_status === NextActionApproval::Pending
                        ? 'awaiting_approval'
                        : 'scheduled'),
            ])
            ->values()
            ->all();
    }

    /**
     * Every office-visit plan waiting for a dispatcher's verdict — all weeks,
     * oldest wanted date first, so nothing waits past its own deadline.
     *
     * @return list<array<string, mixed>>
     */
    private function pendingApprovals(): array
    {
        return NextAction::query()->active()->pending()
            ->where('type', NextActionType::OfficeVisit->value)
            ->where('approval_status', NextActionApproval::Pending->value)
            ->with([
                'assignedTo:id,name',
                'approvalRequestedBy:id,name',
                'subject' => fn (MorphTo $s) => $s
                    ->morphWith([ClientProject::class => ['client:id,first_name,last_name']]),
            ])
            ->orderBy('due_at')
            ->get()
            ->map(function (NextAction $a) {
                $subject = $a->subject;
                $client = $subject instanceof ClientProject ? $subject->client : $subject;

                return [
                    'id' => $a->id,
                    'client' => $client?->full_name,
                    'client_id' => $subject instanceof ClientProject ? $subject->client_id : $subject?->id,
                    'project_id' => $subject instanceof ClientProject ? $subject->id : null,
                    'due_at' => $a->due_at,
                    'day' => $a->due_at?->toDateString(),
                    'time' => $this->wallClock($a->due_at),
                    'requested_by' => $a->approvalRequestedBy?->name,
                    'assigned_to' => $a->assignedTo?->name,
                    'requested_at' => $a->created_at,
                ];
            })
            ->values()
            ->all();
    }

    /** Same idiom as the dispatch board: midnight = "the day is known, the hour is not". */
    private function wallClock(?Carbon $at): ?string
    {
        if ($at === null || ($at->hour === 0 && $at->minute === 0)) {
            return null;
        }

        return $at->format('H:i');
    }
}
