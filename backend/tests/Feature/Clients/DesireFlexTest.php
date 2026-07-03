<?php

declare(strict_types=1);

namespace Tests\Feature\Clients;

use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Clients\Models\Desire;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DesireFlexTest extends TestCase
{
    use RefreshDatabase;

    /** @param  list<string>  $slugs */
    private function userWith(array $slugs, bool $isAgent = false): User
    {
        $role = Role::factory()->create(['is_agent' => $isAgent]);
        $role->permissions()->sync(collect($slugs)->map(
            fn (string $slug) => Permission::firstOrCreate(['slug' => $slug], ['name' => $slug])->id,
        ));

        return User::factory()->create(['role_id' => $role->id]);
    }

    public function test_shifting_a_deal_to_desire_archives_it_and_captures_the_desire(): void
    {
        $client = Client::factory()->create();
        $project = ClientProject::factory()->create(['client_id' => $client->id]);
        Sanctum::actingAs($this->userWith(['clients.view', 'clients.manage', 'clients.create']));

        $this->postJson("/api/v1/projects/{$project->id}/shift-to-desire", ['budget_max' => '3000000'])
            ->assertSuccessful();

        $this->assertDatabaseHas('client_projects', [
            'id' => $project->id, 'status' => 'archived', 'cancellation_reason' => 'Shifted to desire',
        ]);
        $this->assertDatabaseHas('desires', ['client_id' => $client->id, 'budget_max' => '3000000.00']);
    }

    public function test_desire_matches_board_is_agent_scoped(): void
    {
        $agent = $this->userWith(['clients.view', 'units.view'], isAgent: true);
        $client = Client::factory()->create(['assigned_agent_id' => $agent->id]);
        Desire::factory()->create([
            'client_id' => $client->id, 'client_project_id' => null,
            'budget_min' => null, 'budget_max' => '2000.00', 'type_id' => null,
            'wilaya_id' => null, 'commune_id' => null,
        ]);
        Unit::factory()->create(['price' => '1000.00', 'sale_status' => 'available']);

        // The owning agent sees the waiting client with its matches.
        Sanctum::actingAs($agent);
        $this->getJson('/api/v1/desires/matches')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.client.id', $client->id);

        // A different agent's board is empty — scoped to their own book.
        Sanctum::actingAs($this->userWith(['clients.view', 'units.view'], isAgent: true));
        $this->getJson('/api/v1/desires/matches')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_shifting_marks_the_project_as_waiting_on_desire(): void
    {
        $project = ClientProject::factory()->create();
        Sanctum::actingAs($this->userWith(['clients.view', 'clients.manage', 'clients.create', 'projects.view_all']));

        $this->postJson("/api/v1/projects/{$project->id}/shift-to-desire", ['budget_max' => '100'])
            ->assertSuccessful();

        $project->refresh();
        $this->assertNotNull($project->closed_to_desire_at);
        $this->assertTrue($project->isArchived());

        // The badge rides on the resource: step = desire.
        $this->getJson("/api/v1/clients/{$project->client_id}/projects?status=archived")
            ->assertOk()
            ->assertJsonPath('data.0.step', 'desire')
            ->assertJsonPath('data.0.closed_to_desire', true);
    }

    public function test_a_new_call_reopens_the_project_from_the_desire_list(): void
    {
        $client = Client::factory()->create();
        $project = ClientProject::factory()->create(['client_id' => $client->id]);
        Sanctum::actingAs($this->userWith(['clients.view', 'clients.manage', 'clients.create', 'calls.log']));

        $this->postJson("/api/v1/projects/{$project->id}/shift-to-desire", ['budget_max' => '100'])
            ->assertSuccessful();

        // The reconnect call (after a desire match) reactivates the project and
        // attaches to it — the profile reopens from the desire list.
        $this->postJson("/api/v1/clients/{$client->id}/calls", [
            'direction' => 'outbound',
            'next_action' => ['type' => 'call', 'due_date' => now()->addDay()->toDateString()],
        ])->assertCreated();

        $project->refresh();
        $this->assertTrue($project->isActive());
        $this->assertNull($project->closed_to_desire_at);
        $this->assertDatabaseHas('calls', [
            'client_id' => $client->id, 'client_project_id' => $project->id,
        ]);
    }
}
