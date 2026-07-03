<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Actions;

use App\Modules\Clients\Models\ClientProject;
use App\Modules\Pipeline\Enums\NextActionType;
use App\Modules\Pipeline\Models\NextAction;
use App\Modules\Pipeline\Models\Task;
use App\Modules\Pipeline\Models\Visit;
use App\Modules\Settings\Models\User;
use Carbon\CarbonInterface;

/**
 * Everything coming up that involves ONE user — the single inclusion rule
 * shared by the dashboard's "My upcoming" block and the daily digest, so both
 * always agree. A user is involved when the item is assigned to them (agent /
 * assignee) or it lives on a project they contribute to (creator or non-hidden
 * viewer).
 *
 * Groups are kept per type — calls / office visits / in-site visits / tasks —
 * never mixed (visit-type plans are represented by their materialized visits,
 * so only call-type next actions appear as "calls"). Overdue items stay listed
 * until they are completed.
 */
class BuildUpcomingWork
{
    /**
     * @return array{calls: array, office_visits: array, in_site_visits: array, tasks: array}
     */
    public function handle(User $user, CarbonInterface $until): array
    {
        $projectIds = ClientProject::query()
            ->where(fn ($q) => $q
                ->where('created_by', $user->id)
                ->orWhereHas('viewers', fn ($v) => $v
                    ->whereKey($user->id)
                    ->whereNull('client_project_viewers.hidden_at')))
            ->pluck('id');

        $calls = NextAction::query()->active()->pending()
            ->where('type', NextActionType::Call->value)
            ->where('due_at', '<=', $until)
            ->where(fn ($q) => $q
                ->where('assigned_to', $user->id)
                ->orWhere(fn ($s) => $s
                    ->where('subject_type', 'client_project')
                    ->whereIn('subject_id', $projectIds)))
            ->with(['assignedTo:id,name', 'subject'])
            ->orderBy('due_at')
            ->get()
            ->map(fn (NextAction $a) => [
                'id' => $a->id,
                'kind' => 'call',
                'due_at' => $a->due_at,
                'is_overdue' => $a->due_at->isPast(),
                'assigned_to' => $a->assignedTo?->name,
                'client' => $this->clientNameOf($a->subject),
                'link' => $this->linkOf($a->subject),
            ]);

        $visits = Visit::query()->active()
            ->whereNull('completed_at')
            ->where('scheduled_at', '<=', $until)
            ->where(fn ($q) => $q
                ->where('agent_id', $user->id)
                ->orWhereIn('client_project_id', $projectIds))
            ->with(['client:id,first_name,last_name', 'unit.location', 'agent:id,name'])
            ->orderBy('scheduled_at')
            ->get()
            ->map(fn (Visit $v) => [
                'id' => $v->id,
                'kind' => $v->type->value === 'in_site' ? 'in_site_visit' : 'office_visit',
                'due_at' => $v->scheduled_at,
                'is_overdue' => $v->scheduled_at->isPast(),
                'assigned_to' => $v->agent?->name,
                'client' => $v->client?->full_name,
                'unit' => $v->unit?->reference,
                'location' => $v->unit?->location?->name,
                // The field agent opens the site straight in Google Maps.
                'maps_url' => $v->unit?->location?->latitude !== null && $v->unit?->location?->longitude !== null
                    ? sprintf('https://www.google.com/maps/search/?api=1&query=%s,%s', $v->unit->location->latitude, $v->unit->location->longitude)
                    : null,
                'link' => $v->client_project_id
                    ? '/clients/'.$v->client_id.'/projects/'.$v->client_project_id
                    : '/clients/'.$v->client_id,
            ]);

        $tasks = Task::query()->active()->open()
            ->whereNotNull('due_at')
            ->where('due_at', '<=', $until)
            ->where('assigned_to', $user->id)
            ->orderBy('due_at')
            ->get()
            ->map(fn (Task $t) => [
                'id' => $t->id,
                'kind' => 'task',
                'due_at' => $t->due_at,
                'is_overdue' => $t->due_at->isPast(),
                'title' => $t->title,
                'link' => '/tasks',
            ]);

        return [
            'calls' => $calls->values()->all(),
            'office_visits' => $visits->where('kind', 'office_visit')->values()->all(),
            'in_site_visits' => $visits->where('kind', 'in_site_visit')->values()->all(),
            'tasks' => $tasks->values()->all(),
        ];
    }

    private function clientNameOf(?object $subject): ?string
    {
        if ($subject instanceof ClientProject) {
            return $subject->client?->full_name;
        }

        return $subject?->full_name ?? null;
    }

    private function linkOf(?object $subject): ?string
    {
        if ($subject instanceof ClientProject) {
            return '/clients/'.$subject->client_id.'/projects/'.$subject->id;
        }

        return $subject !== null ? '/clients/'.$subject->getKey() : null;
    }
}
