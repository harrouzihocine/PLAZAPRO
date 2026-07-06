<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Clients\Models\Deal;
use App\Modules\Clients\Models\DealItem;
use App\Modules\Pipeline\Models\Call;
use App\Modules\Pipeline\Models\NextAction;
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

    public function test_the_dashboard_is_personal_for_every_role(): void
    {
        // A manager (non-agent) now sees only their OWN book — never the company.
        $manager = $this->user(['dashboard.view'], isAgent: false);
        $other = $this->user(['dashboard.view'], isAgent: false);

        Client::factory()->count(2)->create(['assigned_agent_id' => $manager->id]);
        Client::factory()->create(['created_by' => $manager->id]);
        // Someone else's clients must NOT leak into the manager's dashboard.
        Client::factory()->count(4)->create(['assigned_agent_id' => $other->id, 'created_by' => $other->id]);

        Sanctum::actingAs($manager);

        $this->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonMissingPath('data.scope')
            ->assertJsonMissingPath('data.kpis.payments_due')
            ->assertJsonMissingPath('data.kpis.upcoming_visits')
            ->assertJsonPath('data.kpis.clients', 3)
            ->assertJsonStructure([
                'data' => [
                    'kpis' => ['clients', 'active_projects', 'open_deals', 'overdue_actions'],
                    'month_stats', 'my_upcoming',
                    'my_overdue' => ['calls', 'office_visits', 'in_site_visits', 'tasks'],
                ],
            ]);
    }

    public function test_active_projects_and_open_deals_are_scoped_to_my_book(): void
    {
        $me = $this->user(['dashboard.view'], isAgent: true);
        $other = $this->user(['dashboard.view'], isAgent: true);

        // Mine: a project I created still in play, plus its open (reserved) deal.
        $myProject = ClientProject::factory()->create(['created_by' => $me->id]);
        Deal::factory()->create(['client_project_id' => $myProject->id, 'created_by' => $me->id, 'state' => 'reserved']);

        // Mine but WON — terminal, must not count as "active".
        $wonProject = ClientProject::factory()->create(['created_by' => $me->id, 'stage' => 'won']);

        // Someone else's project + deal — must not leak into my counts.
        $theirProject = ClientProject::factory()->create(['created_by' => $other->id]);
        Deal::factory()->create(['client_project_id' => $theirProject->id, 'created_by' => $other->id, 'state' => 'reserved']);

        Sanctum::actingAs($me);

        $this->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.kpis.active_projects', 1)
            ->assertJsonPath('data.kpis.open_deals', 1);
    }

    public function test_month_stats_count_the_callers_own_activity_this_month(): void
    {
        $me = $this->user(['dashboard.view'], isAgent: true);
        $other = $this->user(['dashboard.view'], isAgent: true);

        Call::factory()->count(2)->create(['agent_id' => $me->id, 'called_at' => now()]);
        Call::factory()->create(['agent_id' => $other->id, 'called_at' => now()]); // not mine
        Call::factory()->create(['agent_id' => $me->id, 'called_at' => now()->subMonthNoOverflow()]); // last month

        Visit::factory()->completed()->create(['agent_id' => $me->id, 'completed_at' => now()]);
        Visit::factory()->inSite()->completed()->create(['agent_id' => $me->id, 'completed_at' => now()]);

        // A deal I closed won this month → counts one won project.
        $project = ClientProject::factory()->create();
        $deal = Deal::factory()->create(['client_project_id' => $project->id, 'created_by' => $me->id]);
        DealItem::factory()->create(['deal_id' => $deal->id, 'state' => 'won', 'closed_at' => now()]);

        Sanctum::actingAs($me);

        $this->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.month_stats.calls', 2)
            ->assertJsonPath('data.month_stats.office_visits', 1)
            ->assertJsonPath('data.month_stats.in_site_visits', 1)
            ->assertJsonPath('data.month_stats.won', 1)
            ->assertJsonPath('data.month_stats.lost', 0);
    }

    public function test_overdue_actions_are_scoped_to_the_caller_and_grouped_by_type(): void
    {
        $me = $this->user(['dashboard.view'], isAgent: true);
        $other = $this->user(['dashboard.view'], isAgent: true);

        // A default NextAction factory is type=call — lands in the "calls" group.
        NextAction::factory()->overdue()->create(['assigned_to' => $me->id]);
        NextAction::factory()->overdue()->create(['assigned_to' => $other->id]); // not mine

        // An overdue, not-yet-completed office visit — sourced from Visit itself
        // (not a NextAction plan), same convention as "my upcoming".
        Visit::factory()->create(['agent_id' => $me->id, 'scheduled_at' => now()->subDay(), 'completed_at' => null]);

        Sanctum::actingAs($me);

        $this->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.kpis.overdue_actions', 2)
            ->assertJsonCount(1, 'data.my_overdue.calls')
            ->assertJsonCount(1, 'data.my_overdue.office_visits')
            ->assertJsonCount(0, 'data.my_overdue.in_site_visits')
            ->assertJsonCount(0, 'data.my_overdue.tasks');
    }

    public function test_the_dashboard_requires_the_dashboard_view_permission(): void
    {
        Sanctum::actingAs($this->user([]));

        $this->getJson('/api/v1/dashboard')->assertForbidden();
    }
}
