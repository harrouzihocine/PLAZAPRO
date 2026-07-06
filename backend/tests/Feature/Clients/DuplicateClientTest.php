<?php

declare(strict_types=1);

namespace Tests\Feature\Clients;

use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientDuplicateRequest;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DuplicateClientTest extends TestCase
{
    use RefreshDatabase;

    /** @param  list<string>  $slugs */
    private function userWith(array $slugs): User
    {
        $role = Role::factory()->create();
        $role->permissions()->sync(collect($slugs)->map(
            fn (string $s) => Permission::firstOrCreate(['slug' => $s], ['name' => $s])->id
        ));

        return User::factory()->create(['role_id' => $role->id]);
    }

    public function test_adding_a_client_whose_phone_belongs_to_another_user_opens_a_request(): void
    {
        $owner = $this->userWith(['clients.view', 'clients.create']);
        $existing = Client::factory()->create(['phone' => '0770123456', 'created_by' => $owner->id]);

        // A finder who cannot see the owner's client (no view_all).
        $finder = $this->userWith(['clients.view', 'clients.create']);
        Sanctum::actingAs($finder);

        // Same number, written differently — still a duplicate.
        $this->postJson('/api/v1/clients', ['phone' => '+213 770 123 456', 'first_name' => 'Sami'])
            ->assertStatus(409)
            ->assertJsonPath('duplicate', true);

        $this->assertDatabaseHas('client_duplicate_requests', [
            'existing_client_id' => $existing->id,
            'requested_by' => $finder->id,
            'status' => 'pending',
        ]);
        // No new client was created.
        $this->assertDatabaseCount('clients', 1);
    }

    public function test_a_client_you_already_own_is_a_plain_conflict_no_request(): void
    {
        $finder = $this->userWith(['clients.view', 'clients.create']);
        Client::factory()->create(['phone' => '0770123456', 'created_by' => $finder->id]);
        Sanctum::actingAs($finder);

        $this->postJson('/api/v1/clients', ['phone' => '0770123456', 'first_name' => 'Sami'])
            ->assertStatus(409)
            ->assertJsonPath('duplicate', true);

        $this->assertDatabaseCount('client_duplicate_requests', 0);
    }

    public function test_a_resolver_can_deny_a_request(): void
    {
        $existing = Client::factory()->create(['phone' => '0770123456']);
        $finder = $this->userWith(['clients.view', 'clients.create']);
        $request = ClientDuplicateRequest::create([
            'existing_client_id' => $existing->id, 'requested_by' => $finder->id, 'status' => 'pending',
        ]);

        Sanctum::actingAs($this->userWith(['clients.duplicates.resolve']));
        $this->postJson("/api/v1/clients/duplicate-requests/{$request->id}/resolve", ['action' => 'deny'])
            ->assertOk()
            ->assertJsonPath('data.status', 'denied');
    }

    public function test_a_resolver_can_share_a_project_with_details(): void
    {
        $owner = $this->userWith(['clients.view', 'clients.create']);
        $existing = Client::factory()->create(['phone' => '0770123456', 'created_by' => $owner->id]);
        $project = ClientProject::factory()->create(['client_id' => $existing->id, 'created_by' => $owner->id]);

        $finder = $this->userWith(['clients.view', 'clients.create']);
        $request = ClientDuplicateRequest::create([
            'existing_client_id' => $existing->id, 'requested_by' => $finder->id, 'status' => 'pending',
        ]);

        Sanctum::actingAs($this->userWith(['clients.duplicates.resolve']));
        $this->postJson("/api/v1/clients/duplicate-requests/{$request->id}/resolve", [
            'action' => 'share_project', 'project_id' => $project->id, 'share_details' => true,
        ])->assertOk()->assertJsonPath('data.status', 'shared');

        // Finder is now a project contributor and can see the client's details.
        $this->assertDatabaseHas('client_project_viewers', [
            'client_project_id' => $project->id, 'user_id' => $finder->id, 'hidden_at' => null,
        ]);
        $this->assertDatabaseHas('client_detail_grants', [
            'client_id' => $existing->id, 'user_id' => $finder->id,
        ]);
    }

    public function test_a_resolver_can_start_a_separate_project_for_the_finder(): void
    {
        $owner = $this->userWith(['clients.view', 'clients.create']);
        $existing = Client::factory()->create(['phone' => '0770123456', 'created_by' => $owner->id]);
        $original = ClientProject::factory()->create(['client_id' => $existing->id, 'created_by' => $owner->id]);

        $finder = $this->userWith(['clients.view', 'clients.create']);
        $request = ClientDuplicateRequest::create([
            'existing_client_id' => $existing->id, 'requested_by' => $finder->id, 'status' => 'pending',
        ]);

        Sanctum::actingAs($this->userWith(['clients.duplicates.resolve']));
        $forkId = $this->postJson("/api/v1/clients/duplicate-requests/{$request->id}/resolve", [
            'action' => 'fork_project', 'project_id' => $original->id,
        ])->assertOk()->assertJsonPath('data.status', 'forked')
            ->json('data.spawned_project_id');

        $this->assertNotNull($forkId);
        $this->assertNotEquals($original->id, $forkId);

        // A NEW project on the same client, owned by the finder, continuing the
        // original but flagged siloed from the client's owner.
        $this->assertDatabaseHas('client_projects', [
            'id' => $forkId,
            'client_id' => $existing->id,
            'created_by' => $finder->id,
            'continued_from_project_id' => $original->id,
            'hidden_from_owner' => true,
        ]);
        // The finder is granted the client's details (they must be able to call it).
        $this->assertDatabaseHas('client_detail_grants', [
            'client_id' => $existing->id, 'user_id' => $finder->id,
        ]);
    }

    public function test_a_forked_project_is_siloed_from_the_owner_both_ways(): void
    {
        $owner = $this->userWith(['clients.view', 'clients.create']);
        $existing = Client::factory()->create(['phone' => '0770123456', 'created_by' => $owner->id]);
        $original = ClientProject::factory()->create(['client_id' => $existing->id, 'created_by' => $owner->id]);

        $finder = $this->userWith(['clients.view', 'clients.create']);
        $request = ClientDuplicateRequest::create([
            'existing_client_id' => $existing->id, 'requested_by' => $finder->id, 'status' => 'pending',
        ]);

        Sanctum::actingAs($this->userWith(['clients.duplicates.resolve']));
        $forkId = $this->postJson("/api/v1/clients/duplicate-requests/{$request->id}/resolve", [
            'action' => 'fork_project', 'project_id' => $original->id,
        ])->json('data.spawned_project_id');

        // The client's own agent sees the original but NOT the finder's fork.
        Sanctum::actingAs($owner);
        $this->getJson("/api/v1/clients/{$existing->id}/projects")
            ->assertOk()
            ->assertJsonFragment(['id' => $original->id])
            ->assertJsonMissing(['id' => $forkId]);

        // The finder sees ONLY their fork — never the original.
        Sanctum::actingAs($finder);
        $this->getJson("/api/v1/clients/{$existing->id}/projects")
            ->assertOk()
            ->assertJsonFragment(['id' => $forkId])
            ->assertJsonMissing(['id' => $original->id]);
    }

    public function test_a_resolver_can_preview_a_projects_details_only_for_the_requests_client(): void
    {
        $existing = Client::factory()->create(['phone' => '0770123456']);
        $project = ClientProject::factory()->create(['client_id' => $existing->id]);
        $foreign = ClientProject::factory()->create(); // a different client's project

        $finder = $this->userWith(['clients.view', 'clients.create']);
        $request = ClientDuplicateRequest::create([
            'existing_client_id' => $existing->id, 'requested_by' => $finder->id, 'status' => 'pending',
        ]);

        Sanctum::actingAs($this->userWith(['clients.duplicates.resolve']));

        $this->getJson("/api/v1/clients/duplicate-requests/{$request->id}/projects/{$project->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $project->id)
            ->assertJsonStructure(['data' => ['id', 'step', 'counts' => ['calls', 'visits', 'shortlist', 'deals']]]);

        // A project from another client is not reachable through this request.
        $this->getJson("/api/v1/clients/duplicate-requests/{$request->id}/projects/{$foreign->id}")
            ->assertNotFound();
    }
}
