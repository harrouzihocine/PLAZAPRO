<?php

declare(strict_types=1);

namespace Tests\Feature\Pipeline;

use App\Modules\Clients\Models\Client;
use App\Modules\Pipeline\Models\NextAction;
use App\Modules\Pipeline\Models\Task;
use App\Modules\Pipeline\Models\Visit;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /me/agenda — the signed-in agent's own workload for the coming days,
 * bucketed per calendar day (the free/busy strip on the next-action form). Own,
 * assigned work only; a fixed run of contiguous days; times pre-formatted (UTC).
 */
class AgentAgendaTest extends TestCase
{
    use RefreshDatabase;

    public function test_buckets_only_my_assigned_work_by_day(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        $client = Client::factory()->create();

        // Mine, in-window: a timed call (+2d), an office visit (+1d), a task (+3d).
        NextAction::factory()->create([
            'subject_type' => 'client', 'subject_id' => $client->id,
            'type' => 'call', 'state' => 'pending', 'assigned_to' => $me->id,
            'due_at' => now()->addDays(2)->startOfDay()->setTime(14, 30),
        ]);
        Visit::factory()->create([
            'client_id' => $client->id, 'agent_id' => $me->id,
            'scheduled_at' => now()->addDay()->startOfDay(), 'completed_at' => null,
        ]);
        Task::factory()->create(['assigned_to' => $me->id, 'due_at' => now()->addDays(3)->startOfDay()]);

        // Not mine / not open / out of window — all excluded.
        NextAction::factory()->create([
            'subject_type' => 'client', 'subject_id' => $client->id,
            'type' => 'call', 'state' => 'pending', 'assigned_to' => $other->id,
            'due_at' => now()->addDay(),
        ]);
        Visit::factory()->create([
            'client_id' => $client->id, 'agent_id' => $me->id,
            'scheduled_at' => now()->addDay(), 'completed_at' => now(),
        ]);
        NextAction::factory()->create([
            'subject_type' => 'client', 'subject_id' => $client->id,
            'type' => 'call', 'state' => 'pending', 'assigned_to' => $me->id,
            'due_at' => now()->addDays(30),
        ]);

        Sanctum::actingAs($me);

        $days = $this->getJson('/api/v1/me/agenda?days=7')->assertOk()->json('data');

        $this->assertCount(7, $days);
        $this->assertSame(now()->toDateString(), $days[0]['date']);

        // Each of my three items landed in its own day and nothing else surfaced.
        $byDate = collect($days)->keyBy('date');
        $this->assertSame([], $byDate[now()->toDateString()]['items']);
        $this->assertSame('office_visit', $byDate[now()->addDay()->toDateString()]['items'][0]['kind']);

        $call = $byDate[now()->addDays(2)->toDateString()]['items'][0];
        $this->assertSame('call', $call['kind']);
        $this->assertSame('14:30', $call['time']); // timed plan keeps its clock time

        $this->assertSame('task', $byDate[now()->addDays(3)->toDateString()]['items'][0]['kind']);

        $total = collect($days)->sum(fn ($d) => count($d['items']));
        $this->assertSame(3, $total);
    }

    public function test_date_only_plan_reads_as_all_day(): void
    {
        $me = User::factory()->create();
        $client = Client::factory()->create();

        // Midnight = the plan carried no specific time — time comes back null.
        NextAction::factory()->create([
            'subject_type' => 'client', 'subject_id' => $client->id,
            'type' => 'call', 'state' => 'pending', 'assigned_to' => $me->id,
            'due_at' => now()->addDay()->startOfDay(),
        ]);

        Sanctum::actingAs($me);

        $items = collect($this->getJson('/api/v1/me/agenda')->assertOk()->json('data'))
            ->firstWhere('date', now()->addDay()->toDateString())['items'];

        $this->assertNull($items[0]['time']);
    }

    public function test_from_shifts_the_window_to_a_later_week(): void
    {
        $me = User::factory()->create();
        $client = Client::factory()->create();

        // A call 9 days out — outside this week, inside the window that starts +7.
        NextAction::factory()->create([
            'subject_type' => 'client', 'subject_id' => $client->id,
            'type' => 'call', 'state' => 'pending', 'assigned_to' => $me->id,
            'due_at' => now()->addDays(9)->startOfDay()->setTime(9, 0),
        ]);

        Sanctum::actingAs($me);

        // This week (default anchor) doesn't reach it.
        $thisWeek = $this->getJson('/api/v1/me/agenda?days=7')->assertOk()->json('data');
        $this->assertSame(0, collect($thisWeek)->sum(fn ($d) => count($d['items'])));

        // Anchored a week ahead, the same window now starts on that day and shows it.
        $from = now()->addDays(7)->toDateString();
        $next = $this->getJson("/api/v1/me/agenda?days=7&from={$from}")->assertOk()->json('data');
        $this->assertSame($from, $next[0]['date']);
        $this->assertSame(
            'call',
            collect($next)->firstWhere('date', now()->addDays(9)->toDateString())['items'][0]['kind'],
        );
    }

    public function test_bad_from_is_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/me/agenda?from=not-a-date')->assertStatus(422);
    }
}
