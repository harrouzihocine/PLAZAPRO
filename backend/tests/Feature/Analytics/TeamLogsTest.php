<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Modules\Pipeline\Models\Call;
use App\Modules\Pipeline\Models\Visit;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeamLogsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  list<string>  $permissions
     */
    private function user(array $permissions): User
    {
        $role = Role::factory()->create();
        $role->permissions()->sync(
            collect($permissions)->map(fn (string $slug) => Permission::firstOrCreate(['slug' => $slug], ['name' => $slug])->id)
        );

        return User::factory()->create(['role_id' => $role->id]);
    }

    public function test_team_logs_requires_the_view_all_permission(): void
    {
        Sanctum::actingAs($this->user(['dashboard.view']));

        $this->getJson('/api/v1/team-logs')->assertForbidden();
    }

    public function test_team_logs_returns_every_users_rapports(): void
    {
        $viewer = $this->user(['logs.view_all']);
        $alice = $this->user([]);
        $bob = $this->user([]);

        Call::factory()->count(2)->create(['agent_id' => $alice->id, 'called_at' => now()]);
        Visit::factory()->completed()->create(['agent_id' => $bob->id, 'completed_at' => now()]);

        Sanctum::actingAs($viewer);

        $this->getJson('/api/v1/team-logs')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('summary.calls', 2)
            ->assertJsonPath('summary.office_visits', 1)
            ->assertJsonPath('meta.total', 3);
    }

    public function test_team_logs_filters_by_user_and_type(): void
    {
        $viewer = $this->user(['logs.view_all']);
        $alice = $this->user([]);
        $bob = $this->user([]);

        Call::factory()->create(['agent_id' => $alice->id, 'called_at' => now()]);
        Call::factory()->create(['agent_id' => $bob->id, 'called_at' => now()]);
        Visit::factory()->completed()->create(['agent_id' => $alice->id, 'completed_at' => now()]);

        Sanctum::actingAs($viewer);

        // Only Alice, only calls → one row.
        $this->getJson('/api/v1/team-logs?user_id='.$alice->id.'&type=call')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.kind', 'call');
    }

    public function test_team_logs_upcoming_mode_returns_scheduled_visits(): void
    {
        $viewer = $this->user(['logs.view_all']);
        $alice = $this->user([]);

        // A future, not-yet-completed visit is "upcoming", not a log.
        Visit::factory()->create(['agent_id' => $alice->id, 'scheduled_at' => now()->addDays(2), 'completed_at' => null]);

        Sanctum::actingAs($viewer);

        $this->getJson('/api/v1/team-logs?mode=upcoming')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.kind', 'office_visit');

        // The same visit is NOT in the default (logged) feed.
        $this->getJson('/api/v1/team-logs')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
