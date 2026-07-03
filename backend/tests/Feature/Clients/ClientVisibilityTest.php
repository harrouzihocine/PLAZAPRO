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
            'id_document_type' => 'passport',
            'id_document_number' => 'P1234567',
            'birth_date' => '1990-05-10',
            'birth_place' => 'Alger',
            'nationality' => 'Algérienne',
            'address' => '12 Rue Didouche Mourad, Alger',
            'occupation' => 'Médecin',
        ])->assertCreated()->json('data.id');

        $this->getJson("/api/v1/clients/{$id}")
            ->assertOk()
            ->assertJsonPath('data.referrer_name', 'Karim Old-Client')
            ->assertJsonPath('data.id_document_type', 'passport')
            ->assertJsonPath('data.id_document_number', 'P1234567')
            ->assertJsonPath('data.birth_date', '1990-05-10');
    }

    public function test_an_unknown_id_document_type_is_rejected(): void
    {
        Sanctum::actingAs($this->userWithPermissions(['clients.create']));

        $this->postJson('/api/v1/clients', ['phone' => '1', 'id_document_type' => 'library_card'])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('id_document_type');
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
            ->assertJsonMissingPath('data.0.id_document_number');
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
}
