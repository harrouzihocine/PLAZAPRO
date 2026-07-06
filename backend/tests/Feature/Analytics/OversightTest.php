<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Pipeline\Enums\VisitType;
use App\Modules\Pipeline\Models\NextAction;
use App\Modules\Pipeline\Models\Visit;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OversightTest extends TestCase
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

    public function test_empty_clients_monitor_lists_them_with_a_row_link(): void
    {
        $creator = User::factory()->create();
        $client = Client::factory()->create([
            'created_by' => $creator->id, 'created_at' => now()->subDays(3),
        ]);

        Sanctum::actingAs($this->userWith(['oversight.clients']));

        $this->getJson('/api/v1/oversight/clients')
            ->assertOk()
            ->assertJsonPath('data.empty.total', 1)
            ->assertJsonPath('data.empty.items.0.link.client_id', $client->id)
            ->assertJsonPath('data.empty.by_user.0.name', $creator->name);
    }

    public function test_empty_clients_surface_immediately_without_a_grace_window(): void
    {
        // A lead captured moments ago with no project/call/desire is already a
        // quality anomaly, so the monitor must not hide it behind a grace window:
        // the 48h grace lives only in the FlagEmptyClients reminder, never here.
        $client = Client::factory()->create(['created_at' => now()]);

        Sanctum::actingAs($this->userWith(['oversight.clients']));

        $this->getJson('/api/v1/oversight/clients')
            ->assertOk()
            ->assertJsonPath('data.empty.total', 1)
            ->assertJsonPath('data.empty.items.0.link.client_id', $client->id);
    }

    public function test_date_filters_narrow_the_monitor(): void
    {
        Client::factory()->create(['created_at' => now()->subDays(3)]);
        Sanctum::actingAs($this->userWith(['oversight.clients']));

        // A future "from" excludes everything.
        $this->getJson('/api/v1/oversight/clients?from=2099-01-01')
            ->assertOk()
            ->assertJsonPath('data.empty.total', 0);
    }

    public function test_summary_only_returns_monitors_the_caller_may_see(): void
    {
        Sanctum::actingAs($this->userWith(['oversight.clients']));

        $data = $this->getJson('/api/v1/oversight/summary')->assertOk()->json('data');

        $this->assertArrayHasKey('clients', $data);
        $this->assertArrayNotHasKey('pipeline', $data);
        $this->assertArrayNotHasKey('drafts', $data);
    }

    public function test_overdue_actions_monitor_carries_the_client_name(): void
    {
        $agent = User::factory()->agent()->create();
        $client = Client::factory()->create();
        $project = ClientProject::factory()->create(['client_id' => $client->id]);

        NextAction::factory()->overdue()->create([
            'subject_type' => $project->getMorphClass(),
            'subject_id' => $project->id,
            'assigned_to' => $agent->id,
        ]);

        Sanctum::actingAs($this->userWith(['oversight.pipeline']));

        $this->getJson('/api/v1/oversight/pipeline')
            ->assertOk()
            ->assertJsonPath('data.overdue.items.0.client', $client->full_name);
    }

    public function test_upcoming_office_visits_monitor_lists_scheduled_visits(): void
    {
        $agent = User::factory()->agent()->create();
        $client = Client::factory()->create();
        $project = ClientProject::factory()->create(['client_id' => $client->id]);

        // Scheduled ahead on a live project — the one to organise.
        Visit::factory()->create([
            'client_id' => $client->id,
            'client_project_id' => $project->id,
            'type' => VisitType::Office->value,
            'agent_id' => $agent->id,
            'scheduled_at' => now()->addDay(),
        ]);
        // Already held — excluded.
        Visit::factory()->completed()->create([
            'client_id' => $client->id, 'type' => VisitType::Office->value,
            'agent_id' => $agent->id, 'scheduled_at' => now()->addDay(),
        ]);
        // In the past and still open — that is a stale visit, not an upcoming one.
        Visit::factory()->create([
            'client_id' => $client->id, 'type' => VisitType::Office->value,
            'agent_id' => $agent->id, 'scheduled_at' => now()->subWeek(),
        ]);

        Sanctum::actingAs($this->userWith(['oversight.pipeline']));

        $this->getJson('/api/v1/oversight/pipeline')
            ->assertOk()
            ->assertJsonPath('data.upcoming_office_visits.total', 1)
            ->assertJsonPath('data.upcoming_office_visits.items.0.link.project_id', $project->id)
            ->assertJsonPath('data.upcoming_office_visits.by_user.0.name', $agent->name);
    }

    public function test_a_monitor_requires_its_own_permission(): void
    {
        Sanctum::actingAs($this->userWith(['clients.view']));
        $this->getJson('/api/v1/oversight/clients')->assertForbidden();
    }
}
