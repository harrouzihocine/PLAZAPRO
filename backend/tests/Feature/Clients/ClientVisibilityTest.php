<?php

declare(strict_types=1);

namespace Tests\Feature\Clients;

use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The visibility permissions:
 *   - clients.view_all      → without it, only own (created / assigned) clients;
 *   - clients.view_details  → without it, only the client's name (no phone/profile);
 *   - projects.view_all     → without it, only own-created + shared-with-me projects;
 *   - projects.contributors → who may share a project (add / hide viewers).
 * Plus the intake changes: optional names ("No name") and referral / identity fields.
 */
class ClientVisibilityTest extends TestCase
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

    // --- Optional names -----------------------------------------------------

    public function test_a_client_can_be_created_with_only_a_phone(): void
    {
        Sanctum::actingAs($this->userWithPermissions(['clients.create', 'clients.view_details']));

        $this->postJson('/api/v1/clients', ['phone' => '+213555000111'])
            ->assertCreated()
            ->assertJsonPath('data.full_name', Client::NO_NAME)
            ->assertJsonPath('data.phone', '+213555000111');
    }

    public function test_the_phone_is_still_required(): void
    {
        Sanctum::actingAs($this->userWithPermissions(['clients.create']));

        $this->postJson('/api/v1/clients', ['first_name' => 'Sara'])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('phone');
    }

    // --- Referral + identity (contract) fields -------------------------------

    public function test_referral_and_identity_fields_round_trip(): void
    {
        Sanctum::actingAs($this->userWithPermissions([
            'clients.view', 'clients.view_all', 'clients.view_details', 'clients.create',
        ]));

        $id = $this->postJson('/api/v1/clients', [
            'phone' => '+213555000111',
            'first_name' => 'Sara', 'last_name' => 'B',
            'referrer_name' => 'Karim Old-Client',
            'referrer_phone' => '+213661222333',
            'id_documents' => [
                ['type' => 'passport', 'number' => 'P1234567', 'issued_at' => '2020-01-15', 'issued_place' => 'Alger'],
                ['type' => 'national_id', 'number' => 'NID-99', 'issued_at' => null, 'issued_place' => null],
            ],
            'id_number' => '109990112233445566',
            'birth_date' => '1990-05-10',
            'birth_place' => 'Alger',
            'address' => '12 Rue Didouche Mourad, Alger',
        ])->assertCreated()->json('data.id');

        $this->getJson("/api/v1/clients/{$id}")
            ->assertOk()
            ->assertJsonPath('data.referrer_name', 'Karim Old-Client')
            ->assertJsonPath('data.id_documents.0.type', 'passport')
            ->assertJsonPath('data.id_documents.0.number', 'P1234567')
            ->assertJsonPath('data.id_documents.0.issued_at', '2020-01-15')
            ->assertJsonPath('data.id_documents.0.issued_place', 'Alger')
            ->assertJsonPath('data.id_documents.1.type', 'national_id')
            ->assertJsonPath('data.id_number', '109990112233445566')
            ->assertJsonPath('data.birth_date', '1990-05-10');
    }

    public function test_an_unknown_id_document_type_is_rejected(): void
    {
        Sanctum::actingAs($this->userWithPermissions(['clients.create']));

        $this->postJson('/api/v1/clients', [
            'phone' => '1',
            'id_documents' => [['type' => 'library_card']],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('id_documents.0.type');
    }

    // --- clients.view_all ----------------------------------------------------

    public function test_without_view_all_a_user_sees_only_own_and_assigned_clients(): void
    {
        $viewer = $this->userWithPermissions(['clients.view']);
        $mine = Client::factory()->create(['created_by' => $viewer->id]);
        $assigned = Client::factory()->create(['assigned_agent_id' => $viewer->id]);
        $foreign = Client::factory()->create();
        Sanctum::actingAs($viewer);

        $ids = collect($this->getJson('/api/v1/clients')->assertOk()->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($mine->id));
        $this->assertTrue($ids->contains($assigned->id));
        $this->assertFalse($ids->contains($foreign->id));

        // A foreign client reads as absent, not forbidden.
        $this->getJson("/api/v1/clients/{$foreign->id}")->assertNotFound();
        $this->getJson("/api/v1/clients/{$mine->id}")->assertOk();
    }

    public function test_with_view_all_every_client_is_listed(): void
    {
        Client::factory()->count(2)->create();
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'clients.view_all']));

        $this->getJson('/api/v1/clients')->assertOk()->assertJsonCount(2, 'data');
    }

    // --- clients.view_details --------------------------------------------------

    public function test_without_view_details_only_the_name_is_exposed(): void
    {
        Client::factory()->create([
            'first_name' => 'Sara', 'last_name' => 'B', 'phone' => '+213555000111',
            'email' => 'sara@example.com', 'notes' => 'VIP',
        ]);
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'clients.view_all']));

        $this->getJson('/api/v1/clients')
            ->assertOk()
            ->assertJsonPath('data.0.full_name', 'B Sara')
            ->assertJsonMissingPath('data.0.phone')
            ->assertJsonMissingPath('data.0.email')
            ->assertJsonMissingPath('data.0.notes')
            ->assertJsonMissingPath('data.0.referrer_name')
            ->assertJsonMissingPath('data.0.id_documents')
            ->assertJsonMissingPath('data.0.id_number');
    }

    // --- projects.view_all + the viewers list ---------------------------------

    public function test_without_projects_view_all_only_own_or_shared_projects_are_listed(): void
    {
        $viewer = $this->userWithPermissions(['clients.view', 'clients.view_all']);
        $client = Client::factory()->create();
        $mine = ClientProject::factory()->create(['client_id' => $client->id, 'created_by' => $viewer->id]);
        $shared = ClientProject::factory()->create(['client_id' => $client->id]);
        $shared->viewers()->attach($viewer->id, ['added_by' => $shared->created_by]);
        $hiddenFromMe = ClientProject::factory()->create(['client_id' => $client->id]);
        $hiddenFromMe->viewers()->attach($viewer->id, ['hidden_at' => now()]);
        $foreign = ClientProject::factory()->create(['client_id' => $client->id]);
        Sanctum::actingAs($viewer);

        $ids = collect($this->getJson("/api/v1/clients/{$client->id}/projects")->assertOk()->json('data'))
            ->pluck('id');

        $this->assertTrue($ids->contains($mine->id));
        $this->assertTrue($ids->contains($shared->id));
        $this->assertFalse($ids->contains($hiddenFromMe->id));
        $this->assertFalse($ids->contains($foreign->id));
    }

    public function test_adding_and_hiding_a_viewer(): void
    {
        $creator = $this->userWithPermissions(['clients.view', 'clients.manage', 'projects.contributors']);
        $colleague = $this->userWithPermissions(['clients.view']);
        $project = ClientProject::factory()->create(['created_by' => $creator->id]);
        Sanctum::actingAs($creator);

        // Add → the colleague appears on the list, un-hidden.
        $this->postJson("/api/v1/projects/{$project->id}/viewers", ['user_id' => $colleague->id])
            ->assertOk()
            ->assertJsonFragment(['id' => $colleague->id, 'hidden' => false]);

        // Hide (no remove) → still listed, flagged hidden.
        $this->postJson("/api/v1/projects/{$project->id}/viewers/{$colleague->id}/hide")
            ->assertOk()
            ->assertJsonFragment(['id' => $colleague->id, 'hidden' => true]);

        // Re-adding un-hides.
        $this->postJson("/api/v1/projects/{$project->id}/viewers", ['user_id' => $colleague->id])
            ->assertOk()
            ->assertJsonFragment(['id' => $colleague->id, 'hidden' => false]);

        // The creator heads the list and cannot be hidden.
        $this->getJson("/api/v1/projects/{$project->id}/viewers")
            ->assertOk()
            ->assertJsonFragment(['id' => $creator->id, 'is_creator' => true]);
        $this->postJson("/api/v1/projects/{$project->id}/viewers/{$creator->id}/hide")
            ->assertStatus(422);
    }

    public function test_sharing_requires_the_contributors_permission(): void
    {
        $user = $this->userWithPermissions(['clients.view', 'clients.manage']);
        $project = ClientProject::factory()->create(['created_by' => $user->id]);
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/projects/{$project->id}/viewers", ['user_id' => $user->id])
            ->assertForbidden();
    }

    // --- Collaborator identity is hidden from name-only lookers ---------------

    /**
     * A user who can list a project (view_all) but not see the client's details
     * (no view_details) and is NOT a member of the project must not learn who is
     * behind it — otherwise they could quietly poach a colleague's client.
     */
    public function test_collaborator_identity_is_hidden_from_name_only_lookers(): void
    {
        $owner = $this->userWithPermissions(['clients.view']);
        $client = Client::factory()->create();
        $project = ClientProject::factory()->create(['client_id' => $client->id, 'created_by' => $owner->id]);

        // Lists every project (projects.view_all) + chat.use, but no view_details,
        // and not a member: chat.use lets the request reach the guard (not the 403 gate).
        $looker = $this->userWithPermissions([
            'clients.view', 'clients.view_all', 'projects.view_all', 'chat.use',
        ]);
        Sanctum::actingAs($looker);

        $this->getJson("/api/v1/clients/{$client->id}/projects")
            ->assertOk()
            ->assertJsonPath('data.0.id', $project->id)
            ->assertJsonPath('data.0.can_view_collaborators', false)
            ->assertJsonMissingPath('data.0.created_by');

        // The "who can see this project" list and the project chat both read as absent.
        $this->getJson("/api/v1/projects/{$project->id}/viewers")->assertNotFound();
        $this->getJson("/api/v1/projects/{$project->id}/conversation")->assertNotFound();
    }

    public function test_a_project_member_without_view_details_still_sees_collaborators(): void
    {
        $owner = $this->userWithPermissions(['clients.view']);
        $client = Client::factory()->create();
        $project = ClientProject::factory()->create(['client_id' => $client->id, 'created_by' => $owner->id]);

        // The creator holds neither view_all nor view_details but IS a member.
        Sanctum::actingAs($owner);

        $this->getJson("/api/v1/clients/{$client->id}/projects")
            ->assertOk()
            ->assertJsonPath('data.0.can_view_collaborators', true)
            ->assertJsonPath('data.0.created_by.id', $owner->id);

        $this->getJson("/api/v1/projects/{$project->id}/viewers")
            ->assertOk()
            ->assertJsonFragment(['id' => $owner->id, 'is_creator' => true]);
    }

    public function test_view_details_unlocks_collaborators_without_membership(): void
    {
        $owner = $this->userWithPermissions(['clients.view']);
        $client = Client::factory()->create();
        $project = ClientProject::factory()->create(['client_id' => $client->id, 'created_by' => $owner->id]);

        // Trusted with the client's details (view_details) though not a member.
        $trusted = $this->userWithPermissions([
            'clients.view', 'clients.view_all', 'projects.view_all', 'clients.view_details',
        ]);
        Sanctum::actingAs($trusted);

        $this->getJson("/api/v1/clients/{$client->id}/projects")
            ->assertOk()
            ->assertJsonPath('data.0.can_view_collaborators', true)
            ->assertJsonPath('data.0.created_by.id', $owner->id);

        $this->getJson("/api/v1/projects/{$project->id}/viewers")->assertOk();
    }

    // --- A client's own agent is never locked out of its projects ------------

    /**
     * The client's assigned agent can list and work every project of that client
     * even without projects.view_all and even on a project a colleague opened
     * (they are neither its creator nor a viewer).
     */
    public function test_the_clients_assigned_agent_is_never_locked_out_of_its_projects(): void
    {
        $agent = $this->userWithPermissions(['clients.view']); // no view_all, not a member
        $client = Client::factory()->create(['assigned_agent_id' => $agent->id]);
        $project = ClientProject::factory()->create(['client_id' => $client->id]); // opened by a colleague
        Sanctum::actingAs($agent);

        $rows = collect($this->getJson("/api/v1/clients/{$client->id}/projects")->assertOk()->json('data'));
        $ids = $rows->pluck('id');
        $this->assertTrue($ids->contains($project->id));

        // It is their own client, so they see who opened it and who works it —
        // the logs' and history's authors — even without clients.view_details.
        $this->assertTrue($rows->firstWhere('id', $project->id)['can_view_collaborators']);
        $this->getJson("/api/v1/projects/{$project->id}/viewers")->assertOk();

        // The project-scoped reads load (no 404) — so the workspace is usable.
        $this->getJson("/api/v1/projects/{$project->id}/shortlist")->assertOk();
        $this->getJson("/api/v1/projects/{$project->id}/deals")->assertOk();
    }

    public function test_the_clients_creator_is_never_locked_out_of_its_projects(): void
    {
        $creator = $this->userWithPermissions(['clients.view']);
        $client = Client::factory()->create(['created_by' => $creator->id]);
        $project = ClientProject::factory()->create(['client_id' => $client->id]);
        Sanctum::actingAs($creator);

        $ids = collect($this->getJson("/api/v1/clients/{$client->id}/projects")->assertOk()->json('data'))
            ->pluck('id');
        $this->assertTrue($ids->contains($project->id));
        $this->getJson("/api/v1/projects/{$project->id}/shortlist")->assertOk();
    }

    /** A non-owner, non-member without projects.view_all still cannot reach it. */
    public function test_a_non_owner_without_view_all_still_cannot_see_the_projects(): void
    {
        // Sees every client (view_all) but owns neither this client nor the project.
        $stranger = $this->userWithPermissions(['clients.view', 'clients.view_all']);
        $client = Client::factory()->create();
        $project = ClientProject::factory()->create(['client_id' => $client->id]);
        Sanctum::actingAs($stranger);

        $ids = collect($this->getJson("/api/v1/clients/{$client->id}/projects")->assertOk()->json('data'))
            ->pluck('id');
        $this->assertFalse($ids->contains($project->id));
        $this->getJson("/api/v1/projects/{$project->id}/shortlist")->assertNotFound();
    }
}
