<?php

declare(strict_types=1);

namespace Tests\Feature\Pipeline;

use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Clients\Models\ShortlistItem;
use App\Modules\Collaboration\Notifications\DomainNotification;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Pipeline\Models\NextAction;
use App\Modules\Pipeline\Models\Visit;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The dispatch flow: an in-site plan may go out unassigned (pending pool →
 * dispatchers notified), the board lists it, and a visits.dispatch holder
 * assigns it to a field agent — which materializes the visits and notifies the
 * agent + the project's contributors. Past days are rejected.
 */
class DispatchTest extends TestCase
{
    use RefreshDatabase;

    /** @param  list<string>  $slugs */
    private function userWith(array $slugs, bool $isAgent = false): User
    {
        $role = Role::factory()->create(['is_agent' => $isAgent]);
        $ids = collect($slugs)->map(
            fn (string $slug) => Permission::firstOrCreate(['slug' => $slug], ['name' => $slug])->id
        );
        $role->permissions()->sync($ids);

        return User::factory()->create(['role_id' => $role->id]);
    }

    /** A project with one shortlisted unit — the in-site prerequisite. */
    private function projectWithShortlist(?int $createdBy = null): ClientProject
    {
        $project = ClientProject::factory()->create([
            'client_id' => Client::factory()->create()->id,
            'created_by' => $createdBy,
        ]);
        ShortlistItem::factory()->create([
            'client_project_id' => $project->id,
            'shortlistable_type' => 'unit',
            'shortlistable_id' => Unit::factory()->create()->id,
            'state' => 'shortlisted',
        ]);

        return $project;
    }

    public function test_an_unassigned_in_site_plan_lands_in_the_pool_and_notifies_dispatchers(): void
    {
        Notification::fake();

        $dispatcher = $this->userWith(['visits.dispatch']);
        $creator = $this->userWith(['clients.view', 'calls.log']);
        $project = $this->projectWithShortlist($creator->id);
        Sanctum::actingAs($creator);

        $this->postJson("/api/v1/clients/{$project->client_id}/calls", [
            'direction' => 'outbound',
            'client_project_id' => $project->id,
            'next_action' => ['type' => 'in_site_visit', 'due_date' => now()->addDays(2)->toDateString()],
        ])->assertCreated();

        // The plan is pending & unassigned; no visit materialized yet.
        $action = NextAction::query()->pending()->firstOrFail();
        $this->assertNull($action->assigned_to);
        $this->assertSame(0, Visit::count());

        Notification::assertSentTo(
            $dispatcher,
            DomainNotification::class,
            fn ($n) => $n->kind === 'dispatch_request' && $n->link === '/dispatch',
        );
    }

    public function test_the_board_lists_the_pool_and_is_permission_gated(): void
    {
        $dispatcher = $this->userWith(['visits.dispatch']);
        $agent = $this->userWith([], isAgent: true);
        $project = $this->projectWithShortlist();

        $action = NextAction::factory()->create([
            'subject_type' => 'client_project', 'subject_id' => $project->id,
            'type' => 'in_site_visit', 'state' => 'pending',
            'assigned_to' => null, 'due_at' => now()->addDay(),
        ]);

        Sanctum::actingAs($this->userWith(['clients.view']));
        $this->getJson('/api/v1/dispatch/board')->assertForbidden();

        Sanctum::actingAs($dispatcher);
        $response = $this->getJson('/api/v1/dispatch/board')->assertOk();

        $this->assertSame($action->id, $response->json('data.pending.0.id'));
        $this->assertContains($agent->id, array_column($response->json('data.agents'), 'id'));
    }

    public function test_a_pending_task_carries_the_shortlisted_units_and_sites_to_visit(): void
    {
        $dispatcher = $this->userWith(['visits.dispatch']);

        $site = Location::factory()->create(['name' => 'Résidence Test']);
        $unit = Unit::factory()->for($site)->create(['reference' => 'B-07']);
        $project = ClientProject::factory()->create(['client_id' => Client::factory()->create()->id]);
        ShortlistItem::factory()->create([
            'client_project_id' => $project->id,
            'shortlistable_type' => 'unit',
            'shortlistable_id' => $unit->id,
            'state' => 'shortlisted',
        ]);
        NextAction::factory()->create([
            'subject_type' => 'client_project', 'subject_id' => $project->id,
            'type' => 'in_site_visit', 'state' => 'pending',
            'assigned_to' => null, 'due_at' => now()->addDay(),
        ]);

        Sanctum::actingAs($dispatcher);
        $response = $this->getJson('/api/v1/dispatch/board')->assertOk();

        $this->assertSame('B-07', $response->json('data.pending.0.units.0.reference'));
        $this->assertSame('Résidence Test', $response->json('data.pending.0.units.0.site'));
        $this->assertSame('Résidence Test', $response->json('data.pending.0.sites.0.name'));
    }

    public function test_assigning_from_the_board_materializes_visits_and_notifies(): void
    {
        Notification::fake();

        $dispatcher = $this->userWith(['visits.dispatch']);
        $agent = $this->userWith([], isAgent: true);
        $contributor = User::factory()->create();
        $project = $this->projectWithShortlist($contributor->id);

        $action = NextAction::factory()->create([
            'subject_type' => 'client_project', 'subject_id' => $project->id,
            'type' => 'in_site_visit', 'state' => 'pending',
            'assigned_to' => null, 'due_at' => now()->addDay(),
        ]);

        Sanctum::actingAs($dispatcher);

        $this->postJson('/api/v1/dispatch/assign', ['changes' => [
            ['kind' => 'action', 'id' => $action->id, 'agent_id' => $agent->id, 'due_date' => now()->addDays(2)->toDateString()],
        ]])->assertOk();

        $this->assertSame($agent->id, $action->fresh()->assigned_to);
        $visit = Visit::query()->firstOrFail();
        $this->assertSame($agent->id, $visit->agent_id);

        // The agent AND the project contributor hear about it.
        Notification::assertSentTo($agent, DomainNotification::class, fn ($n) => $n->kind === 'visit_assigned');
        Notification::assertSentTo($contributor, DomainNotification::class, fn ($n) => $n->kind === 'visit_agent_assigned');
    }

    public function test_assignments_cannot_land_on_a_past_day(): void
    {
        $dispatcher = $this->userWith(['visits.dispatch']);
        $agent = $this->userWith([], isAgent: true);
        $project = $this->projectWithShortlist();

        $action = NextAction::factory()->create([
            'subject_type' => 'client_project', 'subject_id' => $project->id,
            'type' => 'in_site_visit', 'state' => 'pending',
            'assigned_to' => null, 'due_at' => now()->addDay(),
        ]);

        Sanctum::actingAs($dispatcher);

        $this->postJson('/api/v1/dispatch/assign', ['changes' => [
            ['kind' => 'action', 'id' => $action->id, 'agent_id' => $agent->id, 'due_date' => now()->subDay()->toDateString()],
        ]])->assertStatus(422);

        $this->assertNull($action->fresh()->assigned_to);
    }

    public function test_a_visit_can_be_returned_to_the_pool(): void
    {
        Notification::fake();

        $dispatcher = $this->userWith(['visits.dispatch']);
        $agent = $this->userWith([], isAgent: true);
        $project = $this->projectWithShortlist();

        $action = NextAction::factory()->create([
            'subject_type' => 'client_project', 'subject_id' => $project->id,
            'type' => 'in_site_visit', 'state' => 'pending',
            'assigned_to' => $agent->id, 'due_at' => now()->addDay(),
        ]);
        $visit = Visit::factory()->inSite()->create([
            'client_id' => $project->client_id, 'client_project_id' => $project->id,
            'agent_id' => $agent->id, 'next_action_id' => $action->id,
            'scheduled_at' => now()->addDay(), 'completed_at' => null,
        ]);

        Sanctum::actingAs($dispatcher);

        $this->postJson('/api/v1/dispatch/assign', ['changes' => [
            ['kind' => 'visit', 'id' => $visit->id, 'agent_id' => null],
        ]])->assertOk();

        $this->assertNull($action->fresh()->assigned_to);
        $this->assertTrue($visit->fresh()->isCancelled());
        // Back in the pool → dispatchers re-notified.
        Notification::assertSentTo($dispatcher, DomainNotification::class, fn ($n) => $n->kind === 'dispatch_request');
    }
}
