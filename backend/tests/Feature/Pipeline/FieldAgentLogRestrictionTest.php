<?php

declare(strict_types=1);

namespace Tests\Feature\Pipeline;

use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Clients\Models\ShortlistItem;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Pipeline\Models\Visit;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A field agent dispatched to a project is there for the in-site visit only. On
 * a project that is not theirs (they are neither a contributor nor the client's
 * own agent) they may NOT log its calls or complete its office visits — but a
 * visit administrator, the project's own people, and users not dispatched here
 * are unaffected. See ClientProject::isDispatchOnlyAgent.
 */
class FieldAgentLogRestrictionTest extends TestCase
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
            'unit_id' => Unit::factory(),
            'agent_id' => $agent->id,
        ]);
    }

    public function test_a_dispatched_field_agent_cannot_complete_an_office_visit_on_a_project_not_theirs(): void
    {
        $agent = $this->fieldAgentWith(['clients.view', 'visits.conduct']);
        $client = Client::factory()->create();
        $project = ClientProject::factory()->create(['client_id' => $client->id]);
        $this->dispatchTo($agent, $project);

        $office = Visit::factory()->create([
            'client_id' => $client->id, 'client_project_id' => $project->id, 'type' => 'office',
        ]);

        Sanctum::actingAs($agent);
        $this->postJson("/api/v1/visits/{$office->id}/complete", [])->assertForbidden();
    }

    public function test_a_dispatched_field_agent_cannot_log_a_call_on_a_project_not_theirs(): void
    {
        $agent = $this->fieldAgentWith(['clients.view', 'calls.log']);
        $client = Client::factory()->create();
        $project = ClientProject::factory()->create(['client_id' => $client->id]);
        $this->dispatchTo($agent, $project);

        Sanctum::actingAs($agent);
        $this->postJson("/api/v1/clients/{$client->id}/calls", [
            'direction' => 'outbound', 'client_project_id' => $project->id,
        ])->assertForbidden();
    }

    public function test_a_client_level_call_stays_open_to_a_dispatched_field_agent(): void
    {
        // Only PROJECT-scoped logs are restricted — a call with no project is fine.
        $agent = $this->fieldAgentWith(['clients.view', 'calls.log']);
        $client = Client::factory()->create();
        $project = ClientProject::factory()->create(['client_id' => $client->id]);
        $this->dispatchTo($agent, $project);

        Sanctum::actingAs($agent);
        $this->postJson("/api/v1/clients/{$client->id}/calls", [
            'direction' => 'outbound',
            'next_action' => ['type' => 'call', 'due_date' => now()->addDay()->toDateString(), 'assigned_to' => $agent->id],
        ])->assertCreated();
    }

    public function test_the_projects_own_agent_may_log_its_calls_even_when_dispatched_to_it(): void
    {
        // "Unless this project is their project owner": the client's own agent is
        // never dispatch-only, even holding one of the project's in-site visits.
        $agent = $this->fieldAgentWith(['clients.view', 'calls.log']);
        $client = Client::factory()->create(['assigned_agent_id' => $agent->id]);
        $project = ClientProject::factory()->create(['client_id' => $client->id]);
        $this->dispatchTo($agent, $project);

        Sanctum::actingAs($agent);
        $this->postJson("/api/v1/clients/{$client->id}/calls", [
            'direction' => 'outbound', 'client_project_id' => $project->id,
            'next_action' => ['type' => 'call', 'due_date' => now()->addDay()->toDateString(), 'assigned_to' => $agent->id],
        ])->assertCreated();
    }

    public function test_a_visit_administrator_dispatched_here_is_exempt(): void
    {
        // visits.assign (a visit admin) bypasses the dispatch-only restriction.
        $admin = $this->fieldAgentWith(['clients.view', 'calls.log', 'visits.assign']);
        $client = Client::factory()->create();
        $project = ClientProject::factory()->create(['client_id' => $client->id]);
        $this->dispatchTo($admin, $project);

        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/clients/{$client->id}/calls", [
            'direction' => 'outbound', 'client_project_id' => $project->id,
            'next_action' => ['type' => 'call', 'due_date' => now()->addDay()->toDateString(), 'assigned_to' => $admin->id],
        ])->assertCreated();
    }

    public function test_a_conductor_not_dispatched_here_may_still_complete_the_office_visit(): void
    {
        // Regression guard: general office-visit completion is NOT tightened — only
        // a field agent dispatched to THIS project (and nothing more) is blocked.
        $conductor = $this->fieldAgentWith(['clients.view', 'visits.conduct']);
        $client = Client::factory()->create();
        $project = ClientProject::factory()->create(['client_id' => $client->id]);
        ShortlistItem::factory()->create([
            'client_project_id' => $project->id, 'shortlistable_id' => Unit::factory(),
        ]);
        // The conductor holds no in-site visit here → not dispatch-only.
        $office = Visit::factory()->create([
            'client_id' => $client->id, 'client_project_id' => $project->id,
            'type' => 'office', 'agent_id' => $conductor->id,
        ]);

        Sanctum::actingAs($conductor);
        $this->postJson("/api/v1/visits/{$office->id}/complete", [
            'next_action' => ['type' => 'call', 'due_date' => now()->addDay()->toDateString()],
        ])->assertOk();
    }
}
