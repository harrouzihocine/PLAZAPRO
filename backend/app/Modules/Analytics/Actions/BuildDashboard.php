<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Actions;

use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Payments\Models\PaymentSchedule;
use App\Modules\Pipeline\Models\NextAction;
use App\Modules\Pipeline\Models\Visit;
use App\Modules\Settings\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Build the role-aware dashboard for a user. This is a pure read view over the
 * existing domain tables (clients, visits, next_actions, payment_schedules,
 * client_projects) — it never writes.
 *
 * Scoping is enforced HERE, server-side: an agent (is_agent role) sees only
 * their own book (clients they are assigned, visits they conduct, actions
 * assigned to them, payments on their deals); any non-agent with dashboard.view
 * sees the whole company. The caller's identity decides the scope — the client
 * never gets to widen it.
 */
class BuildDashboard
{
    /**
     * @return array<string, mixed>
     */
    public function handle(User $user): array
    {
        // null == "no agent filter" == see everything (managers / admins).
        $agentId = $user->isAgent() ? $user->id : null;

        $upcomingVisits = Visit::query()->active()
            ->whereNull('completed_at')
            ->where('scheduled_at', '>=', now())
            ->when($agentId, fn ($q) => $q->where('agent_id', $agentId));

        $overdueActions = NextAction::query()->active()->overdue()
            ->when($agentId, fn ($q) => $q->where('assigned_to', $agentId));

        $paymentsDue = PaymentSchedule::query()->active()
            ->whereIn('state', ['pending', 'partial', 'overdue'])
            ->when($agentId, fn ($q) => $q->whereHas(
                'clientProject.client',
                fn ($c) => $c->where('assigned_agent_id', $agentId)
            ));

        return [
            'scope' => $agentId ? 'agent' : 'all',
            'kpis' => [
                'clients' => Client::query()->active()
                    ->when($agentId, fn ($q) => $q->where('assigned_agent_id', $agentId))
                    ->count(),
                'upcoming_visits' => (clone $upcomingVisits)->count(),
                'overdue_actions' => (clone $overdueActions)->count(),
                'payments_due' => [
                    'count' => (clone $paymentsDue)->count(),
                    // Exact decimal SUM in the DB; the server is the source of
                    // truth for money, the client never recomputes it.
                    'amount' => number_format(
                        (float) ((clone $paymentsDue)->sum(DB::raw('amount - paid_amount')) ?: 0),
                        2, '.', ''
                    ),
                ],
            ],
            'deals_by_stage' => $this->dealsByStage($agentId),
            'upcoming_visits' => (clone $upcomingVisits)
                ->with(['client:id,first_name,last_name', 'unit:id,reference'])
                ->orderBy('scheduled_at')
                ->limit(5)
                ->get()
                ->map(fn (Visit $v) => [
                    'id' => $v->id,
                    'client' => $v->client ? trim("{$v->client->first_name} {$v->client->last_name}") : null,
                    'unit' => $v->unit?->reference,
                    'type' => $v->type->value,
                    'scheduled_at' => $v->scheduled_at,
                ])->all(),
            'overdue_actions' => (clone $overdueActions)
                ->with('assignedTo:id,name')
                ->orderBy('due_at')
                ->limit(5)
                ->get()
                ->map(fn (NextAction $a) => [
                    'id' => $a->id,
                    'type' => $a->type->value,
                    'due_at' => $a->due_at,
                    'assigned_to' => $a->assignedTo?->name,
                ])->all(),
        ];
    }

    /**
     * Active deals grouped by pipeline stage, scoped to the agent's clients when
     * applicable. Always returns every stage key (0 when none) so the UI is stable.
     *
     * @return array<string, int>
     */
    private function dealsByStage(?int $agentId): array
    {
        $counts = ClientProject::query()->active()
            ->when($agentId, fn ($q) => $q->whereHas(
                'client',
                fn ($c) => $c->where('assigned_agent_id', $agentId)
            ))
            ->selectRaw('stage, COUNT(*) as total')
            ->groupBy('stage')
            ->pluck('total', 'stage');

        return collect(['lead', 'negotiating', 'reserved', 'won', 'lost'])
            ->mapWithKeys(fn (string $stage) => [$stage => (int) $counts->get($stage, 0)])
            ->all();
    }
}
