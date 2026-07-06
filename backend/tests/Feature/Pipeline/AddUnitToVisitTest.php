<?php

declare(strict_types=1);

namespace Tests\Feature\Pipeline;

use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Clients\Models\ShortlistItem;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Pipeline\Models\NextAction;
use App\Modules\Pipeline\Models\Visit;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * "Add unit to visit", standalone: a conducting agent adds apartment(s) to visit
 * on a project without first completing an open in-site visit — the standalone
 * twin of the "another apartment" step, freed from its "only on the last open
 * visit" gate. Assigned → materializes the pending visit(s); unassigned → lands
 * in the dispatch pool. See ProposeInSiteVisit.
 */
class AddUnitToVisitTest extends TestCase
{
    use RefreshDatabase;

    /** @param  list<string>  $slugs */
    private function userWith(array $slugs): User
    {
        $role = Role::factory()->create();
        $ids = collect($slugs)->map(
            fn (string $slug) => Permission::firstOrCreate(['slug' => $slug], ['name' => $slug])->id
        );
        $role->permissions()->sync($ids);

        return User::factory()->create(['role_id' => $role->id]);
    }

    /**
     * A project with two OPEN in-site visits assigned to a field agent (and their
     * shortlist + the assigned plan behind them) — the exact state the user is
     * stuck in: they can't add a unit without completing one first.
     *
     * @return array{0: ClientProject, 1: User, 2: list<Visit>}
     */
    private function projectWithTwoOpenVisits(?int $createdBy = null): array
    {
        $fieldAgent = User::factory()->agent()->create();
        $client = Client::factory()->create();
        $project = ClientProject::factory()->create([
            'client_id' => $client->id, 'created_by' => $createdBy,
        ]);
        $plan = NextAction::factory()->create([
            'subject_type' => 'client_project', 'subject_id' => $project->id,
            'type' => 'in_site_visit', 'assigned_to' => $fieldAgent->id,
        ]);
        $visits = collect([Unit::factory()->create(), Unit::factory()->create()])->map(function ($unit) use ($client, $project, $fieldAgent, $plan) {
            ShortlistItem::factory()->create([
                'client_project_id' => $project->id, 'shortlistable_id' => $unit->id,
            ]);

            return Visit::factory()->inSite()->create([
                'client_id' => $client->id, 'client_project_id' => $project->id,
                'unit_id' => $unit->id, 'agent_id' => $fieldAgent->id, 'next_action_id' => $plan->id,
            ]);
        })->all();

        return [$project, $fieldAgent, $visits];
    }

    public function test_it_adds_a_unit_to_the_dispatch_pool_without_completing_the_open_visits(): void
    {
        $actor = $this->userWith(['clients.view', 'visits.conduct']);
        [$project, , $visits] = $this->projectWithTwoOpenVisits($actor->id);
        $newUnit = Unit::factory()->create();

        Sanctum::actingAs($actor);
        $this->postJson("/api/v1/projects/{$project->id}/in-site-visits", [
            'unit_ids' => [$newUnit->id], 'due_date' => now()->addDays(2)->toDateString(),
        ])->assertOk();

        // The added unit is shortlisted and waiting in the pool (no agent yet).
        $this->assertDatabaseHas('shortlist_items', [
            'client_project_id' => $project->id, 'shortlistable_id' => $newUnit->id, 'status' => 'active',
        ]);
        $pooled = NextAction::query()->active()->pending()
            ->where('subject_id', $project->id)->where('type', 'in_site_visit')
            ->whereNull('assigned_to')->sole();
        $this->assertContains($newUnit->id, $pooled->target_unit_ids);

        // The whole point: the two open visits were never touched.
        foreach ($visits as $v) {
            $this->assertDatabaseHas('visits', ['id' => $v->id, 'status' => 'active']);
            $this->assertNull($v->fresh()->completed_at);
        }
        // Pooled, not materialized — no visit for the new unit yet.
        $this->assertSame(0, Visit::query()->where('unit_id', $newUnit->id)->count());
    }

    public function test_a_dispatcher_may_add_a_unit_assigned_straight_to_a_field_agent(): void
    {
        $actor = $this->userWith(['clients.view', 'visits.conduct', 'visits.dispatch', 'projects.view_all']);
        [$project, $fieldAgent, $visits] = $this->projectWithTwoOpenVisits();
        $newUnit = Unit::factory()->create();

        Sanctum::actingAs($actor);
        $this->postJson("/api/v1/projects/{$project->id}/in-site-visits", [
            'unit_ids' => [$newUnit->id], 'due_date' => now()->addDays(2)->toDateString(),
            'assigned_to' => $fieldAgent->id,
        ])->assertOk();

        // A pending in-site visit for the new unit, assigned to the field agent.
        $this->assertDatabaseHas('visits', [
            'client_project_id' => $project->id, 'unit_id' => $newUnit->id,
            'type' => 'in_site', 'agent_id' => $fieldAgent->id, 'status' => 'active', 'completed_at' => null,
        ]);
        // The two original visits are still open.
        foreach ($visits as $v) {
            $this->assertNull($v->fresh()->completed_at);
            $this->assertSame('active', $v->fresh()->status->value);
        }
    }

    public function test_a_non_dispatcher_addition_is_pooled_even_if_an_agent_is_named(): void
    {
        // Only dispatchers pre-assign; a conductor's named agent is ignored.
        $actor = $this->userWith(['clients.view', 'visits.conduct']);
        [$project, $fieldAgent] = $this->projectWithTwoOpenVisits($actor->id);
        $newUnit = Unit::factory()->create();

        Sanctum::actingAs($actor);
        $this->postJson("/api/v1/projects/{$project->id}/in-site-visits", [
            'unit_ids' => [$newUnit->id], 'due_date' => now()->addDays(2)->toDateString(),
            'assigned_to' => $fieldAgent->id,
        ])->assertOk();

        $this->assertSame(0, Visit::query()->where('unit_id', $newUnit->id)->count());
        $this->assertTrue(
            NextAction::query()->active()->pending()->where('subject_id', $project->id)
                ->where('type', 'in_site_visit')->whereNull('assigned_to')->exists(),
        );
    }

    public function test_it_requires_visits_conduct(): void
    {
        $actor = $this->userWith(['clients.view', 'projects.view_all']);
        [$project] = $this->projectWithTwoOpenVisits();
        $newUnit = Unit::factory()->create();

        Sanctum::actingAs($actor);
        $this->postJson("/api/v1/projects/{$project->id}/in-site-visits", [
            'unit_ids' => [$newUnit->id], 'due_date' => now()->addDays(2)->toDateString(),
        ])->assertForbidden();
    }

    public function test_it_requires_the_project_to_be_visible(): void
    {
        // A conductor who cannot see the project (no view_all, not a member) is out.
        $actor = $this->userWith(['clients.view', 'visits.conduct']);
        [$project] = $this->projectWithTwoOpenVisits();
        $newUnit = Unit::factory()->create();

        Sanctum::actingAs($actor);
        $this->postJson("/api/v1/projects/{$project->id}/in-site-visits", [
            'unit_ids' => [$newUnit->id], 'due_date' => now()->addDays(2)->toDateString(),
        ])->assertForbidden();
    }
}
