<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Payments\Models\PaymentSchedule;
use App\Modules\Pipeline\Models\Visit;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  list<string>  $permissions
     */
    private function user(array $permissions, bool $isAgent = false): User
    {
        $role = Role::factory()->create(['is_agent' => $isAgent]);
        $role->permissions()->sync(
            collect($permissions)->map(fn (string $slug) => Permission::firstOrCreate(['slug' => $slug], ['name' => $slug])->id)
        );

        return User::factory()->create(['role_id' => $role->id]);
    }

    public function test_an_agent_dashboard_is_scoped_to_their_own_book(): void
    {
        $agentA = $this->user(['dashboard.view'], isAgent: true);
        $agentB = $this->user(['dashboard.view'], isAgent: true);

        $aClients = Client::factory()->count(2)->create(['assigned_agent_id' => $agentA->id]);
        $bClients = Client::factory()->count(3)->create(['assigned_agent_id' => $agentB->id]);

        Visit::factory()->create(['agent_id' => $agentA->id, 'scheduled_at' => now()->addDay()]);
        Visit::factory()->count(2)->create(['agent_id' => $agentB->id, 'scheduled_at' => now()->addDay()]);

        // A payment due on one of A's deals — plus one on B's, which must NOT leak.
        $projectA = ClientProject::factory()->create(['client_id' => $aClients->first()->id]);
        PaymentSchedule::factory()->create(['client_project_id' => $projectA->id, 'amount' => '1000.00']);
        $projectB = ClientProject::factory()->create(['client_id' => $bClients->first()->id]);
        PaymentSchedule::factory()->create(['client_project_id' => $projectB->id, 'amount' => '5000.00']);

        Sanctum::actingAs($agentA);

        $this->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.scope', 'agent')
            ->assertJsonPath('data.kpis.clients', 2)
            ->assertJsonPath('data.kpis.upcoming_visits', 1)
            ->assertJsonPath('data.kpis.payments_due.count', 1)
            ->assertJsonPath('data.kpis.payments_due.amount', '1000.00');
    }

    public function test_a_manager_dashboard_sees_the_whole_company(): void
    {
        $agentA = $this->user(['dashboard.view'], isAgent: true);
        $agentB = $this->user(['dashboard.view'], isAgent: true);
        $manager = $this->user(['dashboard.view'], isAgent: false);

        Client::factory()->count(2)->create(['assigned_agent_id' => $agentA->id]);
        Client::factory()->count(3)->create(['assigned_agent_id' => $agentB->id]);

        Sanctum::actingAs($manager);

        $this->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.scope', 'all')
            ->assertJsonPath('data.kpis.clients', 5);
    }

    public function test_the_dashboard_requires_the_dashboard_view_permission(): void
    {
        Sanctum::actingAs($this->user([]));

        $this->getJson('/api/v1/dashboard')->assertForbidden();
    }
}
