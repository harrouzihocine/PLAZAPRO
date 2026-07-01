<?php

declare(strict_types=1);

namespace Tests\Feature\Clients;

use App\Modules\Clients\Models\Client;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientTest extends TestCase
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
        return $this->userWithPermissions(['clients.view', 'clients.create', 'clients.manage']);
    }

    private function agentUser(): User
    {
        return User::factory()->agent()->create();
    }

    public function test_manager_can_create_a_client(): void
    {
        Sanctum::actingAs($this->manager());

        $this->postJson('/api/v1/clients', [
            'first_name' => 'Amine',
            'last_name' => 'Khaled',
            'phone' => '0555123456',
        ])
            ->assertCreated()
            ->assertJsonPath('data.full_name', 'Amine Khaled')
            ->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('clients', [
            'first_name' => 'Amine', 'phone' => '0555123456', 'status' => 'active',
        ]);
    }

    public function test_a_client_can_be_assigned_to_an_agent(): void
    {
        $agent = $this->agentUser();
        Sanctum::actingAs($this->manager());

        $this->postJson('/api/v1/clients', [
            'first_name' => 'Sara', 'last_name' => 'B', 'phone' => '0661000000',
            'assigned_agent_id' => $agent->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.assigned_agent.id', $agent->id);
    }

    public function test_a_client_cannot_be_assigned_to_a_non_agent(): void
    {
        $nonAgent = $this->userWithPermissions(['clients.view']); // role is not is_agent
        Sanctum::actingAs($this->manager());

        $this->postJson('/api/v1/clients', [
            'first_name' => 'Sara', 'last_name' => 'B', 'phone' => '0661000000',
            'assigned_agent_id' => $nonAgent->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('assigned_agent_id');
    }

    public function test_index_filters_by_agent_and_search(): void
    {
        $agent = $this->agentUser();
        Client::factory()->create(['first_name' => 'Yacine', 'last_name' => 'Meziane', 'assigned_agent_id' => $agent->id]);
        Client::factory()->create(['first_name' => 'Nadia', 'last_name' => 'Slimani', 'phone' => '0770999999']);
        Sanctum::actingAs($this->manager());

        $this->getJson("/api/v1/clients?assigned_agent_id={$agent->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.first_name', 'Yacine');

        $this->getJson('/api/v1/clients?search=Slimani')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.first_name', 'Nadia');
    }

    public function test_cancelling_a_client_keeps_the_record(): void
    {
        $client = Client::factory()->create();
        Sanctum::actingAs($this->manager());

        $this->deleteJson("/api/v1/clients/{$client->id}", ['reason' => 'Duplicate'])
            ->assertOk();

        // Row kept, marked cancelled — never deleted.
        $this->assertDatabaseHas('clients', ['id' => $client->id, 'status' => 'cancelled']);
        // And it drops out of the default (active-only) listing.
        $this->getJson('/api/v1/clients')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_creating_requires_clients_create(): void
    {
        Sanctum::actingAs($this->userWithPermissions(['clients.view']));

        $this->postJson('/api/v1/clients', ['first_name' => 'X', 'last_name' => 'Y', 'phone' => '1'])
            ->assertForbidden();
    }

    public function test_editing_requires_clients_manage(): void
    {
        $client = Client::factory()->create();
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'clients.create']));

        $this->putJson("/api/v1/clients/{$client->id}", ['first_name' => 'Changed'])
            ->assertForbidden();
    }

    public function test_agents_picker_returns_only_active_agents(): void
    {
        $agent = $this->agentUser();
        $this->userWithPermissions(['clients.view']); // a non-agent user
        Sanctum::actingAs($this->manager());

        $this->getJson('/api/v1/agents')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $agent->id);
    }
}
