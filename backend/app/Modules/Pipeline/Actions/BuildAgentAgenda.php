<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Actions;

use App\Modules\Clients\Models\ClientProject;
use App\Modules\Pipeline\Enums\NextActionType;
use App\Modules\Pipeline\Models\NextAction;
use App\Modules\Pipeline\Models\Task;
use App\Modules\Pipeline\Models\Visit;
use App\Modules\Settings\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One user's personal workload for the coming days, bucketed per calendar day —
 * the free/busy strip an agent reads while picking WHEN to schedule the next
 * action, so she can avoid piling three visits onto an already-full afternoon.
 *
 * Unlike {@see BuildUpcomingWork} (the dashboard/digest rule, which also pulls in
 * every project the user merely watches), this is strictly what the user must do
 * herself: calls/tasks assigned to her and visits she is the agent for. Days are
 * computed in UTC — the same reference the date <input> and the dispatch board
 * use, so "today" and every column line up with the value a plan is stored under.
 */
class BuildAgentAgenda
{
    /**
     * @return list<array{date: string, items: list<array{kind: string, time: ?string, client: ?string, label: ?string}>}>
     */
    public function handle(User $user, CarbonImmutable $today, int $days): array
    {
        $start = $today->startOfDay();
        $end = $start->addDays($days); // exclusive upper bound (start of day N)

        // Fixed, contiguous buckets — every day is present even when idle, so the
        // strip always renders a full week rather than collapsing empty days.
        $buckets = [];
        for ($i = 0; $i < $days; $i++) {
            $buckets[$start->addDays($i)->toDateString()] = [];
        }

        $push = function (?CarbonImmutable $due, string $kind, ?string $client, ?string $label) use (&$buckets): void {
            if ($due === null) {
                return;
            }
            $day = $due->toDateString();
            if (! array_key_exists($day, $buckets)) {
                return; // out of the window (guards against boundary rounding)
            }
            $buckets[$day][] = [
                'kind' => $kind,
                // Midnight means the plan carried no specific time (date-only) — the
                // UI shows it as an all-day item rather than a misleading "00:00".
                'time' => $due->format('H:i') === '00:00' ? null : $due->format('H:i'),
                'client' => $client,
                'label' => $label,
            ];
        };

        // Calls she owns (visit-type plans are represented by their materialized
        // visits below, so only call plans surface here — no double counting).
        NextAction::query()->active()->pending()
            ->where('type', NextActionType::Call->value)
            ->where('assigned_to', $user->id)
            ->whereBetween('due_at', [$start, $end])
            ->with(['subject' => fn (MorphTo $m) => $m->morphWith([ClientProject::class => ['client:id,first_name,last_name']])])
            ->get()
            ->each(fn (NextAction $a) => $push($a->due_at?->toImmutable(), 'call', $this->clientNameOf($a->subject), null));

        // Visits she is the agent for — office and in-site alike.
        Visit::query()->active()
            ->whereNull('completed_at')
            ->where('agent_id', $user->id)
            ->whereBetween('scheduled_at', [$start, $end])
            ->with(['client:id,first_name,last_name'])
            ->get()
            ->each(fn (Visit $v) => $push(
                $v->scheduled_at?->toImmutable(),
                $v->type->value === 'in_site' ? 'in_site_visit' : 'office_visit',
                $v->client?->full_name,
                null,
            ));

        // Standalone to-dos assigned to her.
        Task::query()->active()->open()
            ->whereNotNull('due_at')
            ->where('assigned_to', $user->id)
            ->whereBetween('due_at', [$start, $end])
            ->get()
            ->each(fn (Task $t) => $push($t->due_at?->toImmutable(), 'task', null, $t->title));

        $out = [];
        foreach ($buckets as $date => $items) {
            // Chronological within the day; timeless items (all-day) sink to the end.
            usort($items, fn ($a, $b) => ($a['time'] ?? '99:99') <=> ($b['time'] ?? '99:99'));
            $out[] = ['date' => $date, 'items' => $items];
        }

        return $out;
    }

    private function clientNameOf(?object $subject): ?string
    {
        if ($subject instanceof ClientProject) {
            return $subject->client?->full_name;
        }

        return $subject?->full_name ?? null;
    }
}
