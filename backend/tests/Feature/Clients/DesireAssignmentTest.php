<?php

declare(strict_types=1);

namespace Tests\Feature\Clients;

use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\Desire;
use App\Modules\Collaboration\Notifications\DomainNotification;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Delegating a desire match: a manager (clients.manage) assigns a waiting client
 * to a sales agent (calls.log), who is notified and reconnects from the client
 * file. The manager triages on the company-wide oversight board; the agent calls.
 */
class DesireAssignmentTest extends TestCase
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

    private function salesAgent(): User
    {
        return $this->userWith(['clients.view', 'calls.log', 'projects.create'], isAgent: true);
    }

    public function test_a_manager_assigns_a_waiting_client_and_the_agent_is_notified(): void
    {
        Notification::fake();

        $manager = $this->userWith(['clients.view', 'clients.manage']);
        $agent = $this->salesAgent();
        $client = Client::factory()->create(['assigned_agent_id' => null]);
        Sanctum::actingAs($manager);

        $this->postJson("/api/v1/clients/{$client->id}/assign-agent", ['agent_id' => $agent->id])
            ->assertOk()
            ->assertJsonPath('data.assigned_agent.id', $agent->id);

        $this->assertDatabaseHas('clients', ['id' => $client->id, 'assigned_agent_id' => $agent->id]);

        Notification::assertSentTo(
            $agent,
            DomainNotification::class,
            fn ($n) => $n->kind === 'desire_assigned' && $n->link === "/clients/{$client->id}",
        );
    }

    public function test_re_assigning_to_the_same_agent_is_a_no_op_and_does_not_re_notify(): void
    {
        Notification::fake();

        $manager = $this->userWith(['clients.view', 'clients.manage']);
        $agent = $this->salesAgent();
        $client = Client::factory()->create(['assigned_agent_id' => $agent->id]);
        Sanctum::actingAs($manager);

        $this->postJson("/api/v1/clients/{$client->id}/assign-agent", ['agent_id' => $agent->id])
            ->assertOk();

        Notification::assertNothingSentTo($agent);
    }

    public function test_the_assignee_must_be_a_sales_agent_who_can_follow_up(): void
    {
        $manager = $this->userWith(['clients.view', 'clients.manage']);
        // A field agent (no calls.log) cannot carry a lead from call to project.
        $fieldOnly = $this->userWith(['visits.conduct']);
        $client = Client::factory()->create();
        Sanctum::actingAs($manager);

        $this->postJson("/api/v1/clients/{$client->id}/assign-agent", ['agent_id' => $fieldOnly->id])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('agent_id');
    }

    public function test_an_agent_cannot_assign_leads_only_a_manager_delegates(): void
    {
        $agent = $this->salesAgent();
        $peer = $this->salesAgent();
        $client = Client::factory()->create();
        Sanctum::actingAs($agent);

        $this->postJson("/api/v1/clients/{$client->id}/assign-agent", ['agent_id' => $peer->id])
            ->assertForbidden();
    }

    public function test_assigning_tags_the_owner_on_the_company_wide_matches_board(): void
    {
        $manager = $this->userWith(['clients.view', 'clients.manage', 'oversight.matches']);
        $agent = $this->salesAgent();

        $client = Client::factory()->create(['assigned_agent_id' => null]);
        Desire::factory()->create([
            'client_id' => $client->id, 'client_project_id' => null,
            'budget_min' => null, 'budget_max' => null, 'type_id' => null,
            'wilaya_id' => null, 'commune_id' => null,
        ]);
        Unit::factory()->create(['price' => '1000.00', 'sale_status' => 'available']);

        // The oversight board shows the waiting client while it is still unassigned.
        Sanctum::actingAs($manager);
        $this->getJson('/api/v1/desires/matches')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.client.id', $client->id)
            ->assertJsonPath('data.items.0.client.assigned_agent', null);

        // The manager delegates — the same row now carries the assigned owner.
        $this->postJson("/api/v1/clients/{$client->id}/assign-agent", ['agent_id' => $agent->id])->assertOk();

        $this->getJson('/api/v1/desires/matches')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.client.assigned_agent.id', $agent->id);
    }
}
