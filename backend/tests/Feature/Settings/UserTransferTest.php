<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Collaboration\Notifications\DomainNotification;
use App\Modules\Pipeline\Enums\NextActionType;
use App\Modules\Pipeline\Models\Call;
use App\Modules\Pipeline\Models\NextAction;
use App\Modules\Pipeline\Models\Task;
use App\Modules\Pipeline\Models\Visit;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The offboarding desk: GET /users/{user}/workload (career + open book) and
 * POST /users/{user}/transfer-work (hand the open book to a successor).
 */
class UserTransferTest extends TestCase
{
    use RefreshDatabase;

    /** @param  list<string>  $slugs */
    private function userWith(array $slugs, bool $agent = false, array $attrs = []): User
    {
        $role = $agent ? Role::factory()->agent()->create() : Role::factory()->create();
        $role->permissions()->sync(collect($slugs)->map(
            fn (string $s) => Permission::firstOrCreate(['slug' => $s], ['name' => $s])->id
        ));

        return User::factory()->create(['role_id' => $role->id] + $attrs);
    }

    /** A leaver with one of everything transferable (and some history). */
    private function leaverWithOpenBook(): array
    {
        $leaver = $this->userWith(['calls.log', 'projects.create'], agent: true);

        $client = Client::factory()->create(['assigned_agent_id' => $leaver->id]);
        $ownCapture = Client::factory()->create([
            'assigned_agent_id' => null, 'created_by' => $leaver->id,
        ]);

        $project = ClientProject::factory()->create([
            'client_id' => $client->id, 'created_by' => $leaver->id,
        ]);

        $action = NextAction::factory()->create([
            'subject_type' => 'client_project', 'subject_id' => $project->id,
            'type' => NextActionType::Call->value, 'assigned_to' => $leaver->id,
        ]);

        $visit = Visit::factory()->create([
            'client_id' => $client->id, 'client_project_id' => $project->id,
            'agent_id' => $leaver->id,
        ]);

        $task = Task::factory()->create(['assigned_to' => $leaver->id]);

        // History — must never move.
        $call = Call::factory()->create(['client_id' => $client->id, 'agent_id' => $leaver->id]);
        $conducted = Visit::factory()->completed()->create([
            'client_id' => $client->id, 'agent_id' => $leaver->id,
        ]);

        return compact('leaver', 'client', 'ownCapture', 'project', 'action', 'visit', 'task', 'call', 'conducted');
    }

    public function test_workload_requires_the_transfer_permission(): void
    {
        $target = User::factory()->create();

        Sanctum::actingAs($this->userWith(['users.manage'])); // manage alone is not enough

        $this->getJson("/api/v1/users/{$target->id}/workload")->assertForbidden();
        $this->postJson("/api/v1/users/{$target->id}/transfer-work", [
            'successor_id' => User::factory()->create()->id,
        ])->assertForbidden();
    }

    public function test_workload_reports_career_and_open_book(): void
    {
        ['leaver' => $leaver] = $this->leaverWithOpenBook();

        Sanctum::actingAs($this->userWith(['users.transfer']));

        $this->getJson("/api/v1/users/{$leaver->id}/workload")
            ->assertOk()
            ->assertJsonPath('data.user.id', $leaver->id)
            ->assertJsonPath('data.career.calls_logged', 1)
            ->assertJsonPath('data.career.visits_conducted', 1)
            ->assertJsonPath('data.open.clients.total', 2)
            ->assertJsonPath('data.open.projects.total', 1)
            ->assertJsonPath('data.open.next_actions.total', 1)
            ->assertJsonPath('data.open.visits.total', 1)
            ->assertJsonPath('data.open.tasks.total', 1)
            ->assertJsonPath('data.open_total', 6);
    }

    public function test_transfer_hands_the_whole_open_book_to_the_successor(): void
    {
        Notification::fake();
        $book = $this->leaverWithOpenBook();
        $leaver = $book['leaver'];
        $successor = $this->userWith(['calls.log', 'projects.create', 'visits.conduct'], agent: true);

        Sanctum::actingAs($this->userWith(['users.transfer']));

        $this->postJson("/api/v1/users/{$leaver->id}/transfer-work", [
            'successor_id' => $successor->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.clients', 2)
            ->assertJsonPath('data.projects', 1)
            ->assertJsonPath('data.next_actions', 1)
            ->assertJsonPath('data.visits', 1)
            ->assertJsonPath('data.tasks', 1);

        // Clients follow the successor (the unassigned own capture too).
        $this->assertSame($successor->id, $book['client']->fresh()->assigned_agent_id);
        $this->assertSame($successor->id, $book['ownCapture']->fresh()->assigned_agent_id);

        // The successor took a contributor seat + the client-detail grant;
        // created_by (provenance) is untouched.
        $project = $book['project']->fresh();
        $this->assertSame($leaver->id, $project->created_by);
        $this->assertTrue($project->viewers()->wherePivotNull('hidden_at')->whereKey($successor->id)->exists());
        $this->assertDatabaseHas('client_detail_grants', [
            'client_id' => $project->client_id, 'user_id' => $successor->id,
        ]);

        // Chat membership followed the contributor list.
        $this->assertDatabaseHas('conversation_user', ['user_id' => $successor->id]);

        // Plans, visits and tasks moved.
        $this->assertSame($successor->id, $book['action']->fresh()->assigned_to);
        $this->assertSame($successor->id, $book['visit']->fresh()->agent_id);
        $this->assertSame($successor->id, $book['task']->fresh()->assigned_to);

        // History stays the leaver's: the logged call and the conducted visit.
        $this->assertSame($leaver->id, $book['call']->fresh()->agent_id);
        $this->assertSame($leaver->id, $book['conducted']->fresh()->agent_id);

        // One summary notification to the successor.
        Notification::assertSentTo($successor, DomainNotification::class,
            fn (DomainNotification $n) => $n->kind === 'work_transferred');
    }

    public function test_transfer_hides_the_leavers_viewer_seat_on_a_colleagues_project(): void
    {
        Notification::fake();
        $leaver = $this->userWith(['projects.create']);
        $colleague = $this->userWith(['projects.create']);
        $successor = $this->userWith(['projects.create']);

        $project = ClientProject::factory()->create(['created_by' => $colleague->id]);
        $project->viewers()->attach($leaver->id, ['added_by' => $colleague->id]);

        Sanctum::actingAs($this->userWith(['users.transfer']));

        $this->postJson("/api/v1/users/{$leaver->id}/transfer-work", [
            'successor_id' => $successor->id,
        ])->assertOk();

        // Leaver off the team, successor in their seat, creator untouched.
        $this->assertTrue($project->viewers()->wherePivotNotNull('hidden_at')->whereKey($leaver->id)->exists());
        $this->assertTrue($project->viewers()->wherePivotNull('hidden_at')->whereKey($successor->id)->exists());
        $this->assertSame($colleague->id, $project->fresh()->created_by);
    }

    public function test_transfer_can_return_in_site_plans_to_the_dispatch_pool(): void
    {
        Notification::fake();
        $leaver = $this->userWith([], agent: true);
        $successor = $this->userWith([], agent: true);
        $dispatcher = $this->userWith(['visits.dispatch']);

        $project = ClientProject::factory()->create();
        $plan = NextAction::factory()->create([
            'subject_type' => 'client_project', 'subject_id' => $project->id,
            'type' => NextActionType::InSiteVisit->value, 'assigned_to' => $leaver->id,
        ]);
        $fieldVisit = Visit::factory()->inSite()->create([
            'client_id' => $project->client_id, 'client_project_id' => $project->id,
            'agent_id' => $leaver->id, 'next_action_id' => $plan->id,
        ]);

        Sanctum::actingAs($this->userWith(['users.transfer']));

        $this->postJson("/api/v1/users/{$leaver->id}/transfer-work", [
            'successor_id' => $successor->id,
            'dispatch_to_pool' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.pooled_plans', 1)
            ->assertJsonPath('data.next_actions', 0)
            ->assertJsonPath('data.visits', 0);

        // The plan waits in the pool; its materialized visit retired with it.
        $this->assertNull($plan->fresh()->assigned_to);
        $this->assertTrue($fieldVisit->fresh()->isCancelled());

        // Dispatchers are told there is work to re-assign.
        Notification::assertSentTo($dispatcher, DomainNotification::class,
            fn (DomainNotification $n) => $n->kind === 'dispatch_request');
    }

    public function test_workload_totals_probe_returns_counts_only(): void
    {
        ['leaver' => $leaver] = $this->leaverWithOpenBook();

        Sanctum::actingAs($this->userWith(['users.transfer']));

        $this->getJson("/api/v1/users/{$leaver->id}/workload?totals=1")
            ->assertOk()
            ->assertJsonPath('data.open_total', 6)
            ->assertJsonMissingPath('data.career');
    }

    public function test_office_work_can_go_to_a_sales_agent_successor(): void
    {
        Notification::fake();
        // A sales agent's book: an office visit + a planned office visit. Sales
        // agents are NOT is_agent (they conduct office rapports, not field
        // work) — the natural same-role successor must be accepted.
        $leaver = $this->userWith(['calls.log', 'projects.create', 'visits.conduct']);
        $client = Client::factory()->create(['assigned_agent_id' => $leaver->id]);
        $visit = Visit::factory()->create(['client_id' => $client->id, 'agent_id' => $leaver->id]);
        $plan = NextAction::factory()->create([
            'type' => NextActionType::OfficeVisit->value, 'assigned_to' => $leaver->id,
        ]);

        $successor = $this->userWith(['calls.log', 'projects.create', 'visits.conduct']);

        Sanctum::actingAs($this->userWith(['users.transfer']));

        $this->postJson("/api/v1/users/{$leaver->id}/transfer-work", [
            'successor_id' => $successor->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.visits', 1)
            ->assertJsonPath('data.next_actions', 1);

        $this->assertSame($successor->id, $visit->fresh()->agent_id);
        $this->assertSame($successor->id, $plan->fresh()->assigned_to);
    }

    public function test_field_plans_block_a_non_agent_successor_unless_pooled(): void
    {
        Notification::fake();
        $leaver = $this->userWith([], agent: true);
        $project = ClientProject::factory()->create();
        NextAction::factory()->create([
            'subject_type' => 'client_project', 'subject_id' => $project->id,
            'type' => NextActionType::InSiteVisit->value, 'assigned_to' => $leaver->id,
        ]);
        $successor = $this->userWith(['visits.conduct']); // can conduct, NOT a field agent

        Sanctum::actingAs($this->userWith(['users.transfer']));

        $this->postJson("/api/v1/users/{$leaver->id}/transfer-work", [
            'successor_id' => $successor->id,
        ])->assertStatus(422);

        // Pooled, the field plan lands on no one — the same successor is fine.
        $this->postJson("/api/v1/users/{$leaver->id}/transfer-work", [
            'successor_id' => $successor->id,
            'dispatch_to_pool' => true,
        ])->assertOk()->assertJsonPath('data.pooled_plans', 1);
    }

    public function test_transfer_rejects_a_successor_who_cannot_receive_the_work(): void
    {
        ['leaver' => $leaver] = $this->leaverWithOpenBook();

        Sanctum::actingAs($this->userWith(['users.transfer']));

        // Cannot follow clients up (no calls.log).
        $this->postJson("/api/v1/users/{$leaver->id}/transfer-work", [
            'successor_id' => $this->userWith(['projects.create'], agent: true)->id,
        ])->assertStatus(422);

        // Cannot hold a project (no projects.create).
        $this->postJson("/api/v1/users/{$leaver->id}/transfer-work", [
            'successor_id' => $this->userWith(['calls.log'], agent: true)->id,
        ])->assertStatus(422);

        // Not an agent — cannot take over visits.
        $this->postJson("/api/v1/users/{$leaver->id}/transfer-work", [
            'successor_id' => $this->userWith(['calls.log', 'projects.create'])->id,
        ])->assertStatus(422);

        // Nothing moved on any failed attempt.
        $this->assertSame(1, NextAction::query()->where('assigned_to', $leaver->id)->count());
    }

    public function test_transfer_rejects_an_inactive_successor_and_self_transfer(): void
    {
        ['leaver' => $leaver] = $this->leaverWithOpenBook();

        Sanctum::actingAs($this->userWith(['users.transfer']));

        $inactive = $this->userWith(['calls.log', 'projects.create'], agent: true, attrs: ['is_active' => false]);

        $this->postJson("/api/v1/users/{$leaver->id}/transfer-work", [
            'successor_id' => $inactive->id,
        ])->assertStatus(422);

        $this->postJson("/api/v1/users/{$leaver->id}/transfer-work", [
            'successor_id' => $leaver->id,
        ])->assertStatus(422);
    }

    public function test_a_staffed_creator_project_is_not_orphaned_work(): void
    {
        Notification::fake();
        $leaver = $this->userWith(['projects.create']);
        $teammate = $this->userWith(['projects.create']);
        $successor = $this->userWith(['projects.create']);

        // Created by the leaver, but a colleague actively works it — the team
        // keeps it; only the sole-owned project below needs a successor.
        $staffed = ClientProject::factory()->create(['created_by' => $leaver->id]);
        $staffed->viewers()->attach($teammate->id, ['added_by' => $leaver->id]);

        $soleOwned = ClientProject::factory()->create(['created_by' => $leaver->id]);

        Sanctum::actingAs($this->userWith(['users.transfer']));

        $this->getJson("/api/v1/users/{$leaver->id}/workload")
            ->assertOk()
            ->assertJsonPath('data.open.projects.total', 1);

        $this->postJson("/api/v1/users/{$leaver->id}/transfer-work", [
            'successor_id' => $successor->id,
        ])->assertOk()->assertJsonPath('data.projects', 1);

        $this->assertTrue($soleOwned->viewers()->wherePivotNull('hidden_at')->whereKey($successor->id)->exists());
        $this->assertFalse($staffed->viewers()->whereKey($successor->id)->exists());

        // The successor's seat took the sole-owned project off the open book:
        // a second run finds nothing and moves nothing (idempotent).
        $this->getJson("/api/v1/users/{$leaver->id}/workload")
            ->assertOk()
            ->assertJsonPath('data.open_total', 0);
        $this->postJson("/api/v1/users/{$leaver->id}/transfer-work", [
            'successor_id' => $successor->id,
        ])->assertOk()->assertJsonPath('data.projects', 0);
    }

    public function test_a_capability_free_book_moves_without_agent_grants(): void
    {
        Notification::fake();
        // A leaver holding ONLY open tasks: any active colleague can take them.
        $leaver = $this->userWith([]);
        Task::factory()->create(['assigned_to' => $leaver->id]);
        $successor = $this->userWith([]);

        Sanctum::actingAs($this->userWith(['users.transfer']));

        $this->postJson("/api/v1/users/{$leaver->id}/transfer-work", [
            'successor_id' => $successor->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.tasks', 1);
    }
}
