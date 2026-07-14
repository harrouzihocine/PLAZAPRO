<?php

declare(strict_types=1);

namespace Tests\Feature\Clients;

use App\Modules\Clients\Models\Client;
use App\Modules\Settings\Models\Commune;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use App\Modules\Settings\Models\Wilaya;
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
        return $this->userWithPermissions([
            'clients.view', 'clients.view_all', 'clients.view_details',
            'clients.create', 'clients.edit', 'clients.manage', 'clients.cancel',
        ]);
    }

    private function agentUser(): User
    {
        return User::factory()->agent()->create();
    }

    /** A sales agent: can log calls, so eligible to follow a client up. */
    private function followUpAgent(): User
    {
        return $this->userWithPermissions(['clients.view', 'calls.log']);
    }

    public function test_manager_can_create_a_client(): void
    {
        Sanctum::actingAs($this->manager());

        $this->postJson('/api/v1/clients', [
            'first_name' => 'amine',
            'last_name' => 'khaled',
            'phone' => '0555123456',
        ])
            ->assertCreated()
            ->assertJsonPath('data.first_name', 'Amine')
            ->assertJsonPath('data.last_name', 'Khaled')
            ->assertJsonPath('data.full_name', 'Khaled Amine')
            ->assertJsonPath('data.status', 'active');

        // Phone is normalized to the DZ "+213 …" convention on write (DzPhone).
        $this->assertDatabaseHas('clients', [
            'first_name' => 'Amine', 'phone' => '+213 555 12 34 56', 'status' => 'active',
        ]);
    }

    public function test_client_carries_an_optional_wilaya_and_commune_of_residence(): void
    {
        $wilaya = Wilaya::factory()->create(['code' => '16', 'name' => 'Alger']);
        $commune = Commune::factory()->create(['wilaya_id' => $wilaya->id, 'name' => 'Hydra']);
        Sanctum::actingAs($this->manager());

        $created = $this->postJson('/api/v1/clients', [
            'phone' => '0555999888',
            'wilaya_id' => $wilaya->id,
            'commune_id' => $commune->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.wilaya.name', 'Alger')
            ->assertJsonPath('data.commune.name', 'Hydra')
            ->json('data.id');

        $this->assertDatabaseHas('clients', [
            'id' => $created, 'wilaya_id' => $wilaya->id, 'commune_id' => $commune->id,
        ]);
    }

    public function test_a_commune_must_belong_to_the_chosen_wilaya(): void
    {
        $wilaya = Wilaya::factory()->create();
        $otherWilaya = Wilaya::factory()->create();
        $foreignCommune = Commune::factory()->create(['wilaya_id' => $otherWilaya->id]);
        Sanctum::actingAs($this->manager());

        $this->postJson('/api/v1/clients', [
            'phone' => '0555111222',
            'wilaya_id' => $wilaya->id,
            'commune_id' => $foreignCommune->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('commune_id');
    }

    public function test_a_client_can_be_assigned_to_a_sales_agent(): void
    {
        $agent = $this->followUpAgent();
        Sanctum::actingAs($this->manager());

        $this->postJson('/api/v1/clients', [
            'first_name' => 'Sara', 'last_name' => 'B', 'phone' => '0661000000',
            'assigned_agent_id' => $agent->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.assigned_agent.id', $agent->id);
    }

    public function test_a_client_cannot_be_assigned_to_a_non_follow_up_user(): void
    {
        $nonAgent = $this->userWithPermissions(['clients.view']); // cannot log calls
        Sanctum::actingAs($this->manager());

        $this->postJson('/api/v1/clients', [
            'first_name' => 'Sara', 'last_name' => 'B', 'phone' => '0661000000',
            'assigned_agent_id' => $nonAgent->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('assigned_agent_id');
    }

    public function test_a_client_cannot_be_assigned_to_a_field_only_agent(): void
    {
        // A field/site agent (is_agent, conducts visits) but with no calls.log —
        // they do not follow clients up, so they are not assignable.
        $fieldAgent = $this->userWithPermissions(['clients.view', 'visits.conduct']);
        Sanctum::actingAs($this->manager());

        $this->postJson('/api/v1/clients', [
            'first_name' => 'Sara', 'last_name' => 'B', 'phone' => '0661000000',
            'assigned_agent_id' => $fieldAgent->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('assigned_agent_id');
    }

    public function test_editing_a_client_keeps_an_already_assigned_invalid_agent(): void
    {
        // A client already carries a follow-up agent who is NOT (or is no longer) a
        // valid one — e.g. a field/site agent assigned before the rule, or an agent
        // who since lost calls.log. Re-submitting that unchanged id while editing an
        // unrelated field must pass: the user cannot act on the error and did not
        // cause it. Only a genuine CHANGE to an invalid agent is rejected.
        $fieldAgent = $this->userWithPermissions(['clients.view', 'visits.conduct']);
        $client = Client::factory()->create(['assigned_agent_id' => $fieldAgent->id]);
        Sanctum::actingAs($this->manager());

        // Editing the rating while echoing back the pre-existing (invalid) agent.
        $this->putJson("/api/v1/clients/{$client->id}", [
            'phone' => $client->phone,
            'assigned_agent_id' => $fieldAgent->id,
            'notes' => 'Follow up next week.',
        ])
            ->assertOk()
            ->assertJsonPath('data.assigned_agent.id', $fieldAgent->id);

        // But switching to a DIFFERENT invalid agent is still rejected.
        $otherField = $this->userWithPermissions(['clients.view', 'visits.conduct']);
        $this->putJson("/api/v1/clients/{$client->id}", [
            'phone' => $client->phone,
            'assigned_agent_id' => $otherField->id,
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

    public function test_index_filters_by_wilaya_and_commune(): void
    {
        $alger = Wilaya::factory()->create(['code' => '16', 'name' => 'Alger']);
        $hydra = Commune::factory()->create(['wilaya_id' => $alger->id, 'name' => 'Hydra']);
        $oran = Wilaya::factory()->create(['code' => '31', 'name' => 'Oran']);

        Client::factory()->create(['first_name' => 'Wilaya', 'wilaya_id' => $alger->id, 'commune_id' => $hydra->id]);
        Client::factory()->create(['first_name' => 'Elsewhere', 'wilaya_id' => $oran->id]);
        Sanctum::actingAs($this->manager());

        $this->getJson("/api/v1/clients?wilaya_id={$alger->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.first_name', 'Wilaya');

        $this->getJson("/api/v1/clients?commune_id={$hydra->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.commune.name', 'Hydra');
    }

    public function test_name_search_matches_the_full_name_in_any_word_order(): void
    {
        Client::factory()->create(['first_name' => 'Ahmed', 'last_name' => 'Benali']);
        Client::factory()->create(['first_name' => 'Sofiane', 'last_name' => 'Meziane']);
        Sanctum::actingAs($this->manager());

        // Full name typed straight — the old first/last substring missed this.
        $this->getJson('/api/v1/clients?search='.urlencode('Ahmed Benali'))
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.last_name', 'Benali');

        // Reversed word order still finds the same client.
        $this->getJson('/api/v1/clients?search='.urlencode('benali ahmed'))
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.last_name', 'Benali');

        // A partial word narrows correctly (not the other client).
        $this->getJson('/api/v1/clients?search=ahm')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.first_name', 'Ahmed');
    }

    public function test_phone_search_is_format_agnostic(): void
    {
        // Stored in compact international form (as the phone input now saves it).
        Client::factory()->create(['first_name' => 'Karim', 'phone' => '+213555042142']);
        Sanctum::actingAs($this->manager());

        // Any way of writing the same number — or a fragment — finds the client.
        foreach (['0555042142', '555042142', '+213 555 042 142', '5042142', '213555'] as $term) {
            $this->getJson('/api/v1/clients?search='.urlencode($term))
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.first_name', 'Karim');
        }

        // A non-matching fragment returns nothing.
        $this->getJson('/api/v1/clients?search=999888')
            ->assertOk()
            ->assertJsonCount(0, 'data');
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

    public function test_editing_requires_clients_edit(): void
    {
        $client = Client::factory()->create();
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'clients.create']));

        $this->putJson("/api/v1/clients/{$client->id}", ['first_name' => 'Changed'])
            ->assertForbidden();
    }

    public function test_clients_edit_alone_edits_info_but_cannot_reassign_or_cancel(): void
    {
        // clients.edit was split out of clients.manage so plain info editing can
        // be granted on its own; the manage levers must stay out of reach.
        $agent = $this->followUpAgent();
        $client = Client::factory()->create(['assigned_agent_id' => $agent->id]);
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'clients.edit']));

        // Editing info works — and the assigned_agent_id the (ownership-masked)
        // form echoes back as null is ignored, not applied: the agent is kept.
        $this->putJson("/api/v1/clients/{$client->id}", [
            'first_name' => 'Changed',
            'assigned_agent_id' => null,
        ])
            ->assertOk()
            ->assertJsonPath('data.first_name', 'Changed');

        $this->assertDatabaseHas('clients', [
            'id' => $client->id, 'first_name' => 'Changed', 'assigned_agent_id' => $agent->id,
        ]);

        // A deliberate reassignment attempt is ignored the same way…
        $other = $this->followUpAgent();
        $this->putJson("/api/v1/clients/{$client->id}", ['assigned_agent_id' => $other->id])
            ->assertOk();
        $this->assertDatabaseHas('clients', ['id' => $client->id, 'assigned_agent_id' => $agent->id]);

        // …and the manage-only endpoints stay forbidden.
        $this->postJson("/api/v1/clients/{$client->id}/assign-agent", ['agent_id' => $other->id])
            ->assertForbidden();
        $this->deleteJson("/api/v1/clients/{$client->id}")->assertForbidden();
    }

    public function test_clients_manage_alone_no_longer_edits_info(): void
    {
        // After the split, manage-without-edit covers reassign / ownership only.
        // Live roles are backfilled with clients.edit on deploy, so this is the
        // shape of a deliberately edit-less role built later in the role editor.
        $client = Client::factory()->create();
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'clients.manage']));

        $this->putJson("/api/v1/clients/{$client->id}", ['first_name' => 'Changed'])
            ->assertForbidden();
    }

    public function test_cancelling_requires_its_own_clients_cancel_grant(): void
    {
        // Cancel was split out of clients.manage into its own lever. Live roles
        // that could cancel via manage are backfilled on deploy, so this is the
        // shape of a manage role deliberately built without cancel.
        $client = Client::factory()->create();

        // manage-without-cancel can no longer cancel a client…
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'clients.manage']));
        $this->deleteJson("/api/v1/clients/{$client->id}")->assertForbidden();

        // …the dedicated clients.cancel grant does.
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'clients.cancel']));
        $this->deleteJson("/api/v1/clients/{$client->id}", ['reason' => 'Duplicate'])
            ->assertOk();
        $this->assertDatabaseHas('clients', ['id' => $client->id, 'status' => 'cancelled']);
    }

    public function test_created_by_is_stamped_and_visible_to_manager(): void
    {
        $manager = $this->manager();
        Sanctum::actingAs($manager);

        $this->postJson('/api/v1/clients', [
            'first_name' => 'Amine', 'last_name' => 'Khaled', 'phone' => '0555123456',
        ])
            ->assertCreated()
            ->assertJsonPath('data.created_by.id', $manager->id)
            ->assertJsonPath('data.created_by.name', $manager->name);

        $this->assertDatabaseHas('clients', ['first_name' => 'Amine', 'created_by' => $manager->id]);
    }

    public function test_ownership_is_hidden_from_non_managers_and_cannot_be_assigned(): void
    {
        $agent = $this->agentUser();
        // A lead-capturing user: can view/create clients but not manage them.
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'clients.create']));

        // The assigned_agent_id is dropped (managers-only), so the client is created unassigned.
        $created = $this->postJson('/api/v1/clients', [
            'first_name' => 'Sara', 'last_name' => 'B', 'phone' => '0661000000',
            'assigned_agent_id' => $agent->id,
        ])
            ->assertCreated()
            ->assertJsonMissingPath('data.assigned_agent')
            ->assertJsonMissingPath('data.created_by')
            ->json('data.id');

        $this->assertDatabaseHas('clients', ['id' => $created, 'assigned_agent_id' => null]);

        // Ownership stays hidden when reading back, too.
        $this->getJson("/api/v1/clients/{$created}")
            ->assertOk()
            ->assertJsonMissingPath('data.assigned_agent')
            ->assertJsonMissingPath('data.created_by');
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

    public function test_follow_up_agents_picker_returns_only_calls_log_users(): void
    {
        $salesAgent = $this->followUpAgent();          // has calls.log → included
        $fieldAgent = $this->userWithPermissions(['clients.view', 'visits.conduct']); // no calls.log → excluded
        Sanctum::actingAs($this->manager());

        $ids = collect($this->getJson('/api/v1/follow-up-agents')->assertOk()->json('data'))
            ->pluck('id');

        $this->assertTrue($ids->contains($salesAgent->id));
        $this->assertFalse($ids->contains($fieldAgent->id));
    }
}
