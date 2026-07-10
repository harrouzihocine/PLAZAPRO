<?php

declare(strict_types=1);

namespace Tests\Feature\Pipeline;

use App\Modules\Collaboration\Notifications\DomainNotification;
use App\Modules\Pipeline\Models\Task;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TaskTest extends TestCase
{
    use RefreshDatabase;

    /** @param  list<string>  $slugs */
    private function userWithPermissions(array $slugs): User
    {
        $role = Role::factory()->create();
        $ids = collect($slugs)->map(
            fn (string $slug) => Permission::firstOrCreate(['slug' => $slug], ['name' => $slug])->id
        );
        $role->permissions()->sync($ids);

        return User::factory()->create(['role_id' => $role->id]);
    }

    public function test_a_task_can_be_created_and_defaults_to_the_actor(): void
    {
        $user = $this->userWithPermissions(['tasks.manage']);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/tasks', ['title' => 'Call the notary'])
            ->assertCreated()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Call the notary')
            ->assertJsonPath('data.0.state', 'open')
            ->assertJsonPath('data.0.assigned_to.id', $user->id);
    }

    public function test_completing_a_task_requires_the_report(): void
    {
        $me = $this->userWithPermissions(['tasks.manage']);
        $task = Task::factory()->create(['assigned_to' => $me->id]);
        Sanctum::actingAs($me);

        $this->postJson("/api/v1/tasks/{$task->id}/complete")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['summary', 'outcome']);
    }

    public function test_a_task_is_completed_with_its_report(): void
    {
        $me = $this->userWithPermissions(['tasks.manage']);
        $task = Task::factory()->create(['assigned_to' => $me->id]);
        Sanctum::actingAs($me);

        $this->postJson("/api/v1/tasks/{$task->id}/complete", [
            'summary' => 'Filmed and edited the apartment tour.',
            'outcome' => 'partial',
            'difficulties' => 'Bad lighting on site.',
            'time_spent_minutes' => 90,
        ])
            ->assertOk()
            ->assertJsonPath('data.state', 'done')
            ->assertJsonPath('data.completion_outcome', 'partial')
            ->assertJsonPath('data.completion_summary', 'Filmed and edited the apartment tour.')
            ->assertJsonPath('data.completed_by.id', $me->id);
    }

    public function test_completing_a_recurring_task_spawns_the_next_occurrence(): void
    {
        $me = $this->userWithPermissions(['tasks.manage']);
        $task = Task::factory()->create([
            'assigned_to' => $me->id,
            'title' => 'Publish a listing story',
            'category' => 'publication',
            'repeat_every_hours' => 12,
        ]);
        Sanctum::actingAs($me);

        $this->postJson("/api/v1/tasks/{$task->id}/complete", [
            'summary' => 'Posted on TikTok and Instagram.',
            'outcome' => 'full',
        ])->assertOk();

        $next = Task::where('id', '!=', $task->id)->where('title', 'Publish a listing story')->first();
        $this->assertNotNull($next);
        $this->assertSame('open', $next->state->value);
        $this->assertSame(12, $next->repeat_every_hours);
        $this->assertTrue($next->due_at->between(now()->addHours(11), now()->addHours(13)));
    }

    public function test_completing_someone_elses_task_needs_tasks_assign(): void
    {
        $task = Task::factory()->create();
        $report = ['summary' => 'Done.', 'outcome' => 'full'];

        Sanctum::actingAs($this->userWithPermissions(['tasks.manage']));
        $this->postJson("/api/v1/tasks/{$task->id}/complete", $report)->assertForbidden();

        Sanctum::actingAs($this->userWithPermissions(['tasks.manage', 'tasks.assign']));
        $this->postJson("/api/v1/tasks/{$task->id}/complete", $report)->assertOk();
    }

    public function test_creating_a_task_for_someone_else_needs_tasks_assign(): void
    {
        $other = User::factory()->create();

        Sanctum::actingAs($this->userWithPermissions(['tasks.manage']));
        $this->postJson('/api/v1/tasks', ['title' => 'X', 'assigned_to' => $other->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['assigned_to']);

        Sanctum::actingAs($this->userWithPermissions(['tasks.manage', 'tasks.assign']));
        $this->postJson('/api/v1/tasks', ['title' => 'X', 'assigned_to' => $other->id])
            ->assertCreated()
            ->assertJsonPath('data.0.assigned_to.id', $other->id);
    }

    public function test_a_task_can_be_fanned_out_to_several_users_each_notified(): void
    {
        Notification::fake();
        $a = User::factory()->create();
        $b = User::factory()->create();
        $me = $this->userWithPermissions(['tasks.manage', 'tasks.assign']);
        Sanctum::actingAs($me);

        $this->postJson('/api/v1/tasks', [
            'title' => 'Film a TikTok tour',
            'assigned_to_ids' => [$a->id, $b->id, $b->id], // duplicate collapses
        ])
            ->assertCreated()
            ->assertJsonCount(2, 'data');

        $this->assertSame(2, Task::where('title', 'Film a TikTok tour')->count());
        $this->assertDatabaseHas('tasks', ['assigned_to' => $a->id, 'created_by' => $me->id]);
        $this->assertDatabaseHas('tasks', ['assigned_to' => $b->id, 'created_by' => $me->id]);
        Notification::assertSentTo([$a, $b], DomainNotification::class);
    }

    public function test_the_fan_out_list_also_needs_tasks_assign(): void
    {
        $other = User::factory()->create();
        Sanctum::actingAs($this->userWithPermissions(['tasks.manage']));

        $this->postJson('/api/v1/tasks', ['title' => 'X', 'assigned_to_ids' => [$other->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['assigned_to']);
    }

    public function test_assigning_a_task_notifies_the_assignee_but_self_tasks_stay_silent(): void
    {
        Notification::fake();
        $other = User::factory()->create();
        Sanctum::actingAs($this->userWithPermissions(['tasks.manage', 'tasks.assign']));

        $this->postJson('/api/v1/tasks', ['title' => 'For me'])->assertCreated();
        Notification::assertNothingSent();

        $this->postJson('/api/v1/tasks', ['title' => 'For you', 'assigned_to' => $other->id])->assertCreated();
        Notification::assertSentTo(
            $other,
            DomainNotification::class,
            fn (DomainNotification $n) => $n->kind === 'task_assigned',
        );
    }

    public function test_completing_a_task_notifies_tasks_assign_holders_for_review(): void
    {
        $manager = $this->userWithPermissions(['tasks.manage', 'tasks.assign']);
        $me = $this->userWithPermissions(['tasks.manage']);
        $task = Task::factory()->create(['assigned_to' => $me->id]);
        Notification::fake();
        Sanctum::actingAs($me);

        $this->postJson("/api/v1/tasks/{$task->id}/complete", [
            'summary' => 'Done.',
            'outcome' => 'full',
        ])->assertOk();

        Notification::assertSentTo(
            $manager,
            DomainNotification::class,
            fn (DomainNotification $n) => $n->kind === 'task_completed',
        );
        // The completer is never their own reviewer.
        Notification::assertNotSentTo($me, DomainNotification::class);
    }

    public function test_without_tasks_assign_the_board_is_personal_even_on_team_scope(): void
    {
        $me = $this->userWithPermissions(['tasks.manage']);
        Task::factory()->create(['assigned_to' => $me->id, 'title' => 'Mine']);
        Task::factory()->create(['title' => 'Someone else']);
        Sanctum::actingAs($me);

        $this->getJson('/api/v1/tasks?scope=team')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Mine');
    }

    public function test_cancelling_a_task_keeps_the_record(): void
    {
        $me = $this->userWithPermissions(['tasks.manage']);
        $task = Task::factory()->create(['assigned_to' => $me->id]);
        Sanctum::actingAs($me);

        $this->deleteJson("/api/v1/tasks/{$task->id}", ['reason' => 'No longer needed'])
            ->assertOk();

        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'state' => 'cancelled', 'status' => 'cancelled']);
    }

    public function test_tasks_require_tasks_manage(): void
    {
        Sanctum::actingAs($this->userWithPermissions(['clients.view']));

        $this->postJson('/api/v1/tasks', ['title' => 'X'])->assertForbidden();
    }

    public function test_scope_mine_returns_only_the_current_users_tasks(): void
    {
        $me = $this->userWithPermissions(['tasks.manage']);
        Task::factory()->create(['assigned_to' => $me->id, 'title' => 'Mine']);
        Task::factory()->create(['title' => 'Someone else']);
        Sanctum::actingAs($me);

        $this->getJson('/api/v1/tasks?scope=mine')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Mine');
    }

    public function test_overdue_filter_returns_only_past_due_open_tasks(): void
    {
        Task::factory()->create(['state' => 'open', 'due_at' => now()->subDay(), 'title' => 'Late']);
        Task::factory()->create(['state' => 'open', 'due_at' => now()->addDay(), 'title' => 'Future']);
        Task::factory()->create(['state' => 'done', 'due_at' => now()->subDay(), 'title' => 'Late but done']);
        Sanctum::actingAs($this->userWithPermissions(['tasks.manage', 'tasks.assign']));

        $this->getJson('/api/v1/tasks?overdue=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Late');
    }

    public function test_priority_filter(): void
    {
        Task::factory()->create(['priority' => 'high', 'title' => 'Urgent']);
        Task::factory()->create(['priority' => 'low', 'title' => 'Whenever']);
        Sanctum::actingAs($this->userWithPermissions(['tasks.manage', 'tasks.assign']));

        $this->getJson('/api/v1/tasks?priority=high')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Urgent');
    }
}
