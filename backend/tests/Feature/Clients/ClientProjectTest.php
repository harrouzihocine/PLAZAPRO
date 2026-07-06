<?php

declare(strict_types=1);

namespace Tests\Feature\Clients;

use App\Modules\Clients\Enums\ClientProjectStage;
use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Pipeline\Models\Call;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientProjectTest extends TestCase
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

    private function manager(): User
    {
        // Mirrors the real Manager role: full visibility + create + manage,
        // incl. the project-level grants split out of the old clients.manage.
        return $this->userWithPermissions([
            'clients.view', 'clients.view_all', 'clients.create', 'clients.manage',
            'projects.create', 'projects.manage', 'projects.advance',
            'units.view', 'units.reserve',
        ]);
    }

    public function test_a_deal_opens_at_the_lead_stage(): void
    {
        $client = Client::factory()->create();
        Call::factory()->create(['client_id' => $client->id]); // call-first rule
        Sanctum::actingAs($this->manager());

        $this->postJson("/api/v1/clients/{$client->id}/projects", [])
            ->assertCreated()
            ->assertJsonPath('data.stage', 'lead');

        $this->assertDatabaseHas('client_projects', [
            'client_id' => $client->id, 'stage' => 'lead', 'status' => 'active',
        ]);
    }

    public function test_a_deal_can_advance_through_a_legal_transition(): void
    {
        $project = ClientProject::factory()->create(); // lead
        Sanctum::actingAs($this->manager());

        $this->postJson("/api/v1/projects/{$project->id}/advance", ['stage' => 'negotiating'])
            ->assertOk()
            ->assertJsonPath('data.stage', 'negotiating');
    }

    public function test_an_illegal_transition_is_rejected(): void
    {
        $project = ClientProject::factory()->create(); // lead
        Sanctum::actingAs($this->manager());

        // lead cannot jump straight to won.
        $this->postJson("/api/v1/projects/{$project->id}/advance", ['stage' => 'won'])
            ->assertStatus(422);

        $this->assertDatabaseHas('client_projects', ['id' => $project->id, 'stage' => 'lead']);
    }

    public function test_won_is_terminal(): void
    {
        $project = ClientProject::factory()->stage(ClientProjectStage::Won)->create();
        Sanctum::actingAs($this->manager());

        $this->postJson("/api/v1/projects/{$project->id}/advance", ['stage' => 'lost'])
            ->assertStatus(422);
    }

    public function test_converting_a_linked_reservation_wins_the_deal(): void
    {
        $client = Client::factory()->create();
        $project = ClientProject::factory()->stage(ClientProjectStage::Reserved)->create(['client_id' => $client->id]);
        $unit = Unit::factory()->create(['sale_status' => 'available', 'price' => 4500000]);
        Sanctum::actingAs($this->manager());

        $reservationId = $this->postJson("/api/v1/units/{$unit->id}/reserve", [
            'client_project_id' => $project->id,
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/reservations/{$reservationId}/convert")
            ->assertOk()
            ->assertJsonPath('data.hold_status', 'converted');

        // The deal is won and stamped with the unit + its price; unit is sold.
        $this->assertDatabaseHas('client_projects', [
            'id' => $project->id, 'stage' => 'won', 'unit_id' => $unit->id, 'total_price' => '4500000.00',
        ]);
        $this->assertSame('sold', $unit->fresh()->sale_status->value);
    }

    public function test_cancelling_a_deal_keeps_the_record(): void
    {
        $project = ClientProject::factory()->create();
        Sanctum::actingAs($this->manager());

        $this->deleteJson("/api/v1/projects/{$project->id}", ['reason' => 'Client withdrew'])
            ->assertOk();

        $this->assertDatabaseHas('client_projects', ['id' => $project->id, 'status' => 'cancelled']);
    }

    public function test_freezing_a_project_blocks_new_activity_until_unfrozen(): void
    {
        $client = Client::factory()->create();
        $project = ClientProject::factory()->create(['client_id' => $client->id]);
        $freezer = $this->userWithPermissions(['clients.view', 'projects.freeze', 'calls.log']);
        Sanctum::actingAs($freezer);

        $this->postJson("/api/v1/projects/{$project->id}/freeze")
            ->assertOk()
            ->assertJsonPath('data.frozen', true);

        // Frozen: no new calls on it.
        $this->postJson("/api/v1/clients/{$client->id}/calls", [
            'direction' => 'outbound',
            'client_project_id' => $project->id,
            'next_action' => ['type' => 'call', 'due_date' => now()->addDay()->toDateString()],
        ])->assertStatus(422);

        // Unfreeze reopens it.
        $this->postJson("/api/v1/projects/{$project->id}/unfreeze")
            ->assertOk()
            ->assertJsonPath('data.frozen', false);

        $this->postJson("/api/v1/clients/{$client->id}/calls", [
            'direction' => 'outbound',
            'client_project_id' => $project->id,
            'next_action' => ['type' => 'call', 'due_date' => now()->addDay()->toDateString()],
        ])->assertCreated();
    }

    public function test_freezing_requires_the_projects_freeze_permission(): void
    {
        $project = ClientProject::factory()->create();
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'clients.manage']));

        $this->postJson("/api/v1/projects/{$project->id}/freeze")->assertForbidden();
    }

    public function test_opening_a_project_requires_projects_create_not_manage(): void
    {
        $client = Client::factory()->create();

        // projects.manage alone (no create) cannot open a project.
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'clients.view_all', 'projects.manage']));
        $this->postJson("/api/v1/clients/{$client->id}/projects", [])->assertForbidden();
    }

    public function test_an_agent_can_open_a_project_on_their_own_client_without_manage(): void
    {
        // A lead-capturing agent (projects.create, no manage, no view_all) opens
        // a project on the client THEY created — the "New project" workflow.
        $agent = $this->userWithPermissions(['clients.view', 'clients.create', 'projects.create']);
        $client = Client::factory()->create(['created_by' => $agent->id]);
        Sanctum::actingAs($agent);

        $this->postJson("/api/v1/clients/{$client->id}/projects", [])
            ->assertCreated()
            ->assertJsonPath('data.stage', 'lead');
    }

    public function test_an_agent_cannot_open_a_project_on_an_out_of_scope_client(): void
    {
        // Has projects.create but neither owns the client nor holds view_all:
        // the client is invisible to them, so opening a project on it is blocked.
        $agent = $this->userWithPermissions(['clients.view', 'clients.create', 'projects.create']);
        $client = Client::factory()->create();
        Sanctum::actingAs($agent);

        $this->postJson("/api/v1/clients/{$client->id}/projects", [])->assertForbidden();
    }
}
