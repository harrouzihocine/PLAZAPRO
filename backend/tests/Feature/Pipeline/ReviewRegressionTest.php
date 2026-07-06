<?php

declare(strict_types=1);

namespace Tests\Feature\Pipeline;

use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Clients\Models\ShortlistItem;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Pipeline\Models\NextAction;
use App\Modules\Pipeline\Models\Visit;
use App\Modules\Settings\Models\DynamicList;
use App\Modules\Settings\Models\DynamicListItem;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regression coverage for the session deep-review findings: stranded pending
 * plans, dispatch re-assignment divergence, cancelled-visit completion,
 * timeline/next-action visibility (IDOR), non-atomic plan creation, and the
 * contributor client-visibility extension.
 */
class ReviewRegressionTest extends TestCase
{
    use RefreshDatabase;

    /** @param  list<string>  $slugs */
    private function userWith(array $slugs, bool $isAgent = false): User
    {
        $role = Role::factory()->create(['is_agent' => $isAgent]);
        $ids = collect($slugs)->map(
            fn (string $slug) => Permission::firstOrCreate(['slug' => $slug], ['name' => $slug])->id
        );
        $role->permissions()->sync($ids);

        return User::factory()->create(['role_id' => $role->id]);
    }

    /** A next_action_change_reasons list item id — the reason a plan was corrected. */
    private function changeReasonId(string $label = 'Changed the type of next step'): int
    {
        $list = DynamicList::firstOrCreate(
            ['key' => 'next_action_change_reasons'],
            ['name' => 'Change Reasons', 'is_system' => true],
        );

        return DynamicListItem::create([
            'dynamic_list_id' => $list->id, 'label' => $label, 'value' => 'changed_type', 'is_active' => true,
        ])->id;
    }

    public function test_logging_a_call_without_a_follow_up_closes_the_fulfilled_plan(): void
    {
        $actor = $this->userWith(['clients.view', 'clients.view_all', 'calls.log']);
        $client = Client::factory()->create();
        $plan = NextAction::factory()->create([
            'subject_type' => 'client', 'subject_id' => $client->id,
            'type' => 'call', 'state' => 'pending', 'assigned_to' => $actor->id,
            'due_at' => now(),
        ]);

        Sanctum::actingAs($actor);
        // Concluding onto the desire list (no next step) still fulfils the plan.
        $this->postJson("/api/v1/clients/{$client->id}/calls", [
            'direction' => 'outbound',
            'closure' => ['type' => 'desire', 'desire' => ['notes' => 'Waiting on inventory.']],
        ])->assertCreated();

        // The plan was fulfilled by this very call — it must not stay pending.
        $this->assertSame('done', $plan->fresh()->state->value);
        $this->assertNotNull($plan->fresh()->completed_at);
    }

    public function test_a_new_plan_cancels_an_undispatched_pool_plan_instead_of_faking_done(): void
    {
        $actor = $this->userWith(['clients.view', 'clients.view_all', 'next_actions.plan']);
        $client = Client::factory()->create();
        $project = ClientProject::factory()->create(['client_id' => $client->id]);
        ShortlistItem::factory()->create([
            'client_project_id' => $project->id,
            'shortlistable_type' => 'unit',
            'shortlistable_id' => Unit::factory()->create()->id,
            'state' => 'shortlisted',
        ]);
        $poolPlan = NextAction::factory()->create([
            'subject_type' => 'client_project', 'subject_id' => $project->id,
            'type' => 'in_site_visit', 'state' => 'pending', 'assigned_to' => null,
            'due_at' => now()->addDay(),
        ]);

        Sanctum::actingAs($actor);
        $this->postJson("/api/v1/clients/{$client->id}/next-actions", [
            'client_project_id' => $project->id,
            'type' => 'call',
            'due_date' => now()->addDays(2)->toDateString(),
            'assigned_to' => $actor->id,
        ])->assertCreated();

        // Never executed → cancelled (with a reason), NOT marked done.
        $fresh = $poolPlan->fresh();
        $this->assertTrue($fresh->isCancelled());
        $this->assertNull($fresh->completed_at);
    }

    public function test_planning_a_next_action_is_atomic_when_visit_sync_rejects(): void
    {
        $actor = $this->userWith(['clients.view', 'clients.view_all', 'next_actions.plan']);
        $client = Client::factory()->create();
        $prior = NextAction::factory()->create([
            'subject_type' => 'client', 'subject_id' => $client->id,
            'type' => 'call', 'state' => 'pending', 'assigned_to' => $actor->id,
            'due_at' => now(),
        ]);

        Sanctum::actingAs($actor);
        // In-site on a bare client (no project/shortlist) → sync rejects.
        $this->postJson("/api/v1/clients/{$client->id}/next-actions", [
            'type' => 'in_site_visit',
            'due_date' => now()->addDay()->toDateString(),
        ])->assertStatus(422);

        // Nothing half-committed: the prior plan is still the open one and no
        // orphan in-site action survived.
        $this->assertSame('pending', $prior->fresh()->state->value);
        $this->assertSame(1, NextAction::query()->active()->pending()->count());
    }

    public function test_reassigning_a_dispatched_plan_moves_its_open_visits_to_the_new_agent(): void
    {
        $dispatcher = $this->userWith(['visits.dispatch']);
        $agentA = $this->userWith([], isAgent: true);
        $agentB = $this->userWith([], isAgent: true);

        $project = ClientProject::factory()->create(['client_id' => Client::factory()->create()->id]);
        ShortlistItem::factory()->create([
            'client_project_id' => $project->id,
            'shortlistable_type' => 'unit',
            'shortlistable_id' => Unit::factory()->create()->id,
            'state' => 'shortlisted',
        ]);
        $plan = NextAction::factory()->create([
            'subject_type' => 'client_project', 'subject_id' => $project->id,
            'type' => 'in_site_visit', 'state' => 'pending', 'assigned_to' => null,
            'due_at' => now()->addDay(),
        ]);

        Sanctum::actingAs($dispatcher);

        // Assign to A, then (second save) re-assign to B for another day.
        $this->postJson('/api/v1/dispatch/assign', ['changes' => [
            ['kind' => 'action', 'id' => $plan->id, 'agent_id' => $agentA->id, 'due_date' => now()->addDay()->toDateString()],
        ]])->assertOk();
        $this->postJson('/api/v1/dispatch/assign', ['changes' => [
            ['kind' => 'action', 'id' => $plan->id, 'agent_id' => $agentB->id, 'due_date' => now()->addDays(2)->toDateString()],
        ]])->assertOk();

        // The materialized visit followed the plan — no divergence.
        $visit = Visit::query()->active()->whereNull('completed_at')->firstOrFail();
        $this->assertSame($agentB->id, $visit->agent_id);
        $this->assertSame($agentB->id, $plan->fresh()->assigned_to);
        $this->assertSame(
            now()->addDays(2)->toDateString(),
            $visit->scheduled_at->toDateString(),
        );
    }

    public function test_a_cancelled_visit_cannot_be_completed(): void
    {
        $actor = $this->userWith(['clients.view', 'visits.conduct']);
        $visit = Visit::factory()->create();
        $visit->cancel('Returned to the dispatch pool');

        Sanctum::actingAs($actor);
        $this->postJson("/api/v1/visits/{$visit->id}/complete", [])
            ->assertStatus(422);

        $this->assertNull($visit->fresh()->completed_at);
    }

    public function test_timeline_and_next_action_planning_respect_client_visibility(): void
    {
        // No clients.view_all: other agents' clients read as absent.
        $outsider = $this->userWith(['clients.view', 'next_actions.plan']);
        $client = Client::factory()->create(); // created_by/assigned elsewhere

        Sanctum::actingAs($outsider);
        $this->getJson("/api/v1/clients/{$client->id}/timeline")->assertNotFound();
        $this->postJson("/api/v1/clients/{$client->id}/next-actions", [
            'type' => 'call', 'due_date' => now()->addDay()->toDateString(), 'assigned_to' => $outsider->id,
        ])->assertNotFound();
    }

    public function test_a_project_contributor_can_read_the_projects_client(): void
    {
        // Being shared a project must include its client, or the project
        // workspace 404s on its own client.
        $viewer = $this->userWith(['clients.view', 'calls.log']);
        $client = Client::factory()->create();
        $project = ClientProject::factory()->create(['client_id' => $client->id]);
        $project->viewers()->attach($viewer->id, ['added_by' => $viewer->id, 'hidden_at' => null]);

        Sanctum::actingAs($viewer);
        $this->getJson("/api/v1/clients/{$client->id}")->assertOk();
        $this->getJson("/api/v1/clients/{$client->id}/timeline")->assertOk();
    }

    public function test_correcting_into_an_in_site_plan_never_inherits_a_non_agent_assignee(): void
    {
        $actor = $this->userWith(['clients.view', 'clients.view_all', 'calls.log']);
        $backOffice = $this->userWith([], isAgent: false);
        $client = Client::factory()->create();
        $project = ClientProject::factory()->create(['client_id' => $client->id]);
        ShortlistItem::factory()->create([
            'client_project_id' => $project->id,
            'shortlistable_type' => 'unit',
            'shortlistable_id' => Unit::factory()->create()->id,
            'state' => 'shortlisted',
        ]);
        $plan = NextAction::factory()->create([
            'subject_type' => 'client_project', 'subject_id' => $project->id,
            'type' => 'call', 'state' => 'pending', 'assigned_to' => $backOffice->id,
            'due_at' => now()->addDay(),
        ]);

        Sanctum::actingAs($actor);
        $this->postJson("/api/v1/next-actions/{$plan->id}/correct", [
            'reason_id' => $this->changeReasonId(), 'note' => 'client wants to see it on site',
            'type' => 'in_site_visit',
            'due_date' => now()->addDays(2)->toDateString(),
        ])->assertCreated();

        // The non-agent owner was NOT inherited — the plan went to the pool.
        $corrected = NextAction::query()->active()->pending()->firstOrFail();
        $this->assertNull($corrected->assigned_to);
        $this->assertSame(0, Visit::query()->active()->whereNull('completed_at')->count());
    }
}
