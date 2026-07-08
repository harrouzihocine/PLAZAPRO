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

    public function test_without_view_all_a_user_sees_only_their_own_logs(): void
    {
        $viewer = $this->user(['dashboard.view']);
        $other = $this->user([]);

        Call::factory()->create(['agent_id' => $viewer->id, 'called_at' => now()]);
        Call::factory()->count(2)->create(['agent_id' => $other->id, 'called_at' => now()]);

        Sanctum::actingAs($viewer);

        // The feed is pinned to their own logs — the two others' calls never show.
        $this->getJson('/api/v1/team-logs')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('summary.calls', 1);

        // …and a user_id in the request cannot widen it to someone else.
        $this->getJson('/api/v1/team-logs?user_id='.$other->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('summary.calls', 1);
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

    public function test_upcoming_mode_lists_soonest_planned_work_first(): void
    {
        $alice = $this->user(['dashboard.view']);

        Visit::factory()->create(['agent_id' => $alice->id, 'scheduled_at' => now()->addDays(5), 'completed_at' => null]);
        Visit::factory()->create(['agent_id' => $alice->id, 'scheduled_at' => now()->addHours(2), 'completed_at' => null]);

        Sanctum::actingAs($alice);

        $res = $this->getJson('/api/v1/team-logs?mode=upcoming')->assertOk()->json();

        // Soonest first — the item due in two hours must never hide behind next week's.
        $this->assertTrue($res['data'][0]['at'] < $res['data'][1]['at']);
    }

    public function test_all_mode_merges_logged_and_planned_chronologically(): void
    {
        $alice = $this->user(['dashboard.view']);

        // This morning's logged rapports + a visit planned for later today.
        Call::factory()->create(['agent_id' => $alice->id, 'called_at' => now()->subHours(3)]);
        Visit::factory()->completed()->create(['agent_id' => $alice->id, 'completed_at' => now()->subHour()]);
        Visit::factory()->create(['agent_id' => $alice->id, 'scheduled_at' => now()->addHours(4), 'completed_at' => null]);

        Sanctum::actingAs($alice);

        $res = $this->getJson('/api/v1/team-logs?mode=all&from='.now()->toDateString())
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->json();

        // Agenda order (morning → evening), done work flagged apart from planned.
        $this->assertSame(['call', 'office_visit', 'office_visit'], array_column($res['data'], 'kind'));
        $this->assertSame([false, false, true], array_column($res['data'], 'planned'));
    }
}
