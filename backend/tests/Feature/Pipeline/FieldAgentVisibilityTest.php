<?php

declare(strict_types=1);

namespace Tests\Feature\Pipeline;

use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Pipeline\Models\NextAction;
use App\Modules\Pipeline\Models\Visit;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A field agent dispatched to a project's in-site visit must SEE that project
 * and its client (the assignment notification deep-links there) — but nothing
 * beyond: only the dispatched project, and only their in-site remit on it (no
 * calls, no office-visit completion, no standalone planning). Reassigning or
 * cancelling the visit withdraws the access on its own. See
 * ClientProject::isDispatchedFieldAgent / isDispatchOnlyAgent.
 */
class FieldAgentVisibilityTest extends TestCase
{
    use RefreshDatabase;

    /** @param  list<string>  $slugs */
    private function fieldAgentWith(array $slugs): User
    {
        $role = Role::factory()->agent()->create();
        $ids = collect($slugs)->map(
            fn (string $slug) => Permission::firstOrCreate(['slug' => $slug], ['name' => $slug])->id
        );
        $role->permissions()->sync($ids);

        return User::factory()->create(['role_id' => $role->id]);
    }

    /** Dispatch $agent to $project by giving them one of its in-site visits. */
    private function dispatchTo(User $agent, ClientProject $project): Visit
    {
        return Visit::factory()->inSite()->create([
            'client_id' => $project->client_id,
            'client_project_id' => $project->id,
            'agent_id' => $agent->id,
        ]);
    }

    // --- The dispatched project and its client become visible ----------------

    public function test_a_dispatched_field_agent_sees_the_client_in_their_clients_list(): void
    {
        $agent = $this->fieldAgentWith(['clients.view']);
        $client = Client::factory()->create();
        $project = ClientProject::factory()->create(['client_id' => $client->id]);
        $this->dispatchTo($agent, $project);

        Sanctum::actingAs($agent);
        $ids = collect($this->getJson('/api/v1/clients')->assertOk()->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($client->id));
    }

    public function test_a_dispatched_field_agent_can_open_the_client_file(): void
    {
        $agent = $this->fieldAgentWith(['clients.view']);
        $client = Client::factory()->create();
        $project = ClientProject::factory()->create(['client_id' => $client->id]);
        $this->dispatchTo($agent, $project);

        Sanctum::actingAs($agent);
        $this->getJson("/api/v1/clients/{$client->id}")->assertOk();
    }

    public function test_a_dispatched_field_agent_sees_only_the_dispatched_project(): void
    {
        $agent = $this->fieldAgentWith(['clients.view']);
        $client = Client::factory()->create();
        $dispatched = ClientProject::factory()->create(['client_id' => $client->id]);
        $other = ClientProject::factory()->create(['client_id' => $client->id]);
        $this->dispatchTo($agent, $dispatched);

        Sanctum::actingAs($agent);
        $ids = collect($this->getJson("/api/v1/clients/{$client->id}/projects")->assertOk()->json('data'))
            ->pluck('id');

        $this->assertTrue($ids->contains($dispatched->id));
        $this->assertFalse($ids->contains($other->id));
    }

    public function test_the_timeline_carries_the_dispatched_projects_visit(): void
    {
        $agent = $this->fieldAgentWith(['clients.view']);
        $client = Client::factory()->create();
        $project = ClientProject::factory()->create(['client_id' => $client->id]);
        $visit = $this->dispatchTo($agent, $project);

        Sanctum::actingAs($agent);
        $visitIds = collect(
            $this->getJson("/api/v1/clients/{$client->id}/timeline")->assertOk()->json('data.visits')
        )->pluck('id');

        $this->assertTrue($visitIds->contains($visit->id));
    }

    public function test_a_completed_visit_keeps_the_project_visible(): void
    {
        // The agent keeps their completed log's story — only a CANCELLED
        // (superseded / returned-to-pool) visit withdraws the tie.
        $agent = $this->fieldAgentWith(['clients.view']);
        $client = Client::factory()->create();
        $project = ClientProject::factory()->create(['client_id' => $client->id]);
        $this->dispatchTo($agent, $project)->update(['completed_at' => now()]);

        Sanctum::actingAs($agent);
        $this->getJson("/api/v1/clients/{$client->id}")->assertOk();
        $ids = collect($this->getJson("/api/v1/clients/{$client->id}/projects")->assertOk()->json('data'))
            ->pluck('id');
        $this->assertTrue($ids->contains($project->id));
    }

    // --- …and is withdrawn with the dispatch itself ---------------------------

    public function test_reassigning_the_visit_revokes_the_replaced_agents_access(): void
    {
        $agent = $this->fieldAgentWith(['clients.view']);
        $replacement = User::factory()->agent()->create();
        $client = Client::factory()->create();
        $project = ClientProject::factory()->create(['client_id' => $client->id]);
        $visit = $this->dispatchTo($agent, $project);

        $visit->update(['agent_id' => $replacement->id]);

        Sanctum::actingAs($agent);
        $this->getJson("/api/v1/clients/{$client->id}")->assertNotFound();
        $ids = collect($this->getJson('/api/v1/clients')->assertOk()->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($client->id));
    }

    public function test_a_cancelled_visit_grants_no_access(): void
    {
        $agent = $this->fieldAgentWith(['clients.view']);
        $client = Client::factory()->create();
        $project = ClientProject::factory()->create(['client_id' => $client->id]);
        $this->dispatchTo($agent, $project)->cancel('Returned to the pool');

        Sanctum::actingAs($agent);
        $this->getJson("/api/v1/clients/{$client->id}")->assertNotFound();
    }

    // --- The remit stays the in-site visit: nothing else ---------------------

    public function test_the_project_is_flagged_dispatch_only_for_the_field_agent(): void
    {
        $agent = $this->fieldAgentWith(['clients.view']);
        $client = Client::factory()->create();
        $project = ClientProject::factory()->create(['client_id' => $client->id]);
        $this->dispatchTo($agent, $project);

        Sanctum::actingAs($agent);
        $data = collect($this->getJson("/api/v1/clients/{$client->id}/projects")->assertOk()->json('data'))
            ->firstWhere('id', $project->id);

        $this->assertTrue($data['is_dispatch_only']);
    }

    public function test_the_projects_creator_is_not_flagged_dispatch_only(): void
    {
        $creator = $this->fieldAgentWith(['clients.view']);
        $client = Client::factory()->create();
        $project = ClientProject::factory()->create([
            'client_id' => $client->id, 'created_by' => $creator->id,
        ]);
        // Even while holding the project's in-site visit themselves.
        $this->dispatchTo($creator, $project);

        Sanctum::actingAs($creator);
        $data = collect($this->getJson("/api/v1/clients/{$client->id}/projects")->assertOk()->json('data'))
            ->firstWhere('id', $project->id);

        $this->assertFalse($data['is_dispatch_only']);
    }

    public function test_a_dispatched_field_agent_cannot_plan_standalone_on_the_project(): void
    {
        $agent = $this->fieldAgentWith(['clients.view', 'next_actions.plan']);
        $client = Client::factory()->create();
        $project = ClientProject::factory()->create(['client_id' => $client->id]);
        $this->dispatchTo($agent, $project);

        Sanctum::actingAs($agent);
        $this->postJson("/api/v1/clients/{$client->id}/next-actions", [
            'client_project_id' => $project->id,
            'type' => 'call', 'due_date' => now()->addDay()->toDateString(),
        ])->assertForbidden();
    }

    public function test_a_client_level_plan_stays_open_to_a_dispatched_field_agent(): void
    {
        $agent = $this->fieldAgentWith(['clients.view', 'next_actions.plan']);
        $client = Client::factory()->create();
        $project = ClientProject::factory()->create(['client_id' => $client->id]);
        $this->dispatchTo($agent, $project);

        Sanctum::actingAs($agent);
        $this->postJson("/api/v1/clients/{$client->id}/next-actions", [
            'type' => 'call', 'due_date' => now()->addDay()->toDateString(),
        ])->assertCreated();
    }

    public function test_a_dispatched_field_agent_cannot_correct_the_projects_plan(): void
    {
        $agent = $this->fieldAgentWith(['clients.view', 'calls.log']);
        $client = Client::factory()->create();
        $project = ClientProject::factory()->create(['client_id' => $client->id]);
        $this->dispatchTo($agent, $project);

        $plan = NextAction::factory()->create([
            'subject_type' => 'client_project', 'subject_id' => $project->id,
        ]);

        Sanctum::actingAs($agent);
        $this->postJson("/api/v1/next-actions/{$plan->id}/correct", [
            'reason_id' => 1, 'type' => 'call', 'due_date' => now()->addDay()->toDateString(),
        ])->assertForbidden();
    }

    // --- The chat button on the now-visible project page ----------------------

    public function test_a_dispatched_field_agent_can_open_the_projects_chat(): void
    {
        $agent = $this->fieldAgentWith(['clients.view', 'chat.use']);
        $client = Client::factory()->create();
        $project = ClientProject::factory()->create(['client_id' => $client->id]);
        $this->dispatchTo($agent, $project);

        Sanctum::actingAs($agent);
        // 201 on first use — the project's conversation is created on the way in.
        $this->getJson("/api/v1/projects/{$project->id}/conversation")->assertSuccessful();
    }
}
