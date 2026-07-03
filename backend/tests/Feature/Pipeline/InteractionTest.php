<?php

declare(strict_types=1);

namespace Tests\Feature\Pipeline;

use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Clients\Models\ShortlistItem;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Pipeline\Models\Call;
use App\Modules\Pipeline\Models\NextAction;
use App\Modules\Pipeline\Models\Visit;
use App\Modules\Settings\Models\DynamicList;
use App\Modules\Settings\Models\DynamicListItem;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InteractionTest extends TestCase
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

    private function agent(): User
    {
        return User::factory()->agent()->create();
    }

    private function nextActionPayload(User $assignee): array
    {
        return ['type' => 'call', 'due_date' => now()->addDay()->toDateString(), 'assigned_to' => $assignee->id];
    }

    public function test_logging_a_call_records_it_and_creates_a_pending_next_action(): void
    {
        $client = Client::factory()->create();
        $agent = $this->agent();
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'calls.log']));

        $this->postJson("/api/v1/clients/{$client->id}/calls", [
            'direction' => 'outbound',
            'next_action' => $this->nextActionPayload($agent),
        ])->assertCreated()->assertJsonPath('data.direction', 'outbound');

        $this->assertDatabaseHas('calls', ['client_id' => $client->id, 'direction' => 'outbound']);
        $this->assertSame(1, NextAction::query()->pending()
            ->where('subject_type', 'client')->where('subject_id', $client->id)->count());
    }

    public function test_logging_a_call_without_a_next_action_is_allowed(): void
    {
        // Optional plan: some calls genuinely end a thread — the call is logged
        // and simply leaves no pending action (one can be planned later).
        $client = Client::factory()->create();
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'calls.log']));

        $this->postJson("/api/v1/clients/{$client->id}/calls", ['direction' => 'outbound'])
            ->assertCreated();

        $this->assertDatabaseHas('calls', ['client_id' => $client->id, 'direction' => 'outbound']);
        $this->assertSame(0, NextAction::count());
    }

    public function test_a_next_action_can_be_planned_after_the_fact(): void
    {
        $client = Client::factory()->create();
        $agent = $this->agent();
        // view_all: this test exercises the planning mechanics, not visibility
        // (ReviewRegressionTest covers the visibility rule).
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'clients.view_all', 'calls.log']));

        // A call closed its thread; the plan arrives later, standalone.
        $this->postJson("/api/v1/clients/{$client->id}/calls", ['direction' => 'outbound'])->assertCreated();

        $this->postJson("/api/v1/clients/{$client->id}/next-actions", [
            'type' => 'call',
            'due_date' => now()->addDays(2)->toDateString(),
            'assigned_to' => $agent->id,
        ])->assertCreated();

        $this->assertSame(1, NextAction::query()->pending()
            ->where('subject_type', 'client')->where('subject_id', $client->id)->count());
    }

    public function test_retired_next_action_types_are_rejected(): void
    {
        $client = Client::factory()->create();
        $agent = $this->agent();
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'calls.log']));

        // The workflow allows exactly call / office_visit / in_site_visit.
        foreach (['follow_up', 'send_docs'] as $retired) {
            $this->postJson("/api/v1/clients/{$client->id}/calls", [
                'direction' => 'outbound',
                'next_action' => ['type' => $retired, 'due_date' => now()->addDay()->toDateString(), 'assigned_to' => $agent->id],
            ])->assertStatus(422)->assertJsonValidationErrorFor('next_action.type');
        }

        $this->assertSame(0, NextAction::count());
    }

    public function test_a_subject_keeps_exactly_one_open_pending_next_action(): void
    {
        $client = Client::factory()->create();
        $agent = $this->agent();
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'calls.log']));

        $this->postJson("/api/v1/clients/{$client->id}/calls", ['direction' => 'outbound', 'next_action' => $this->nextActionPayload($agent)])->assertCreated();
        $this->postJson("/api/v1/clients/{$client->id}/calls", ['direction' => 'inbound', 'next_action' => $this->nextActionPayload($agent)])->assertCreated();

        // Two calls logged, but only one pending action for the subject; the first is done.
        $this->assertSame(1, NextAction::query()->pending()
            ->where('subject_type', 'client')->where('subject_id', $client->id)->count());
        $this->assertSame(1, NextAction::query()->where('state', 'done')
            ->where('subject_type', 'client')->where('subject_id', $client->id)->count());
    }

    public function test_a_call_next_action_defaults_the_assignee_to_the_clients_sales_agent(): void
    {
        $salesAgent = $this->agent();
        $client = Client::factory()->create(['assigned_agent_id' => $salesAgent->id]);
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'calls.log']));

        // assigned_to omitted — a call next action should fall back to the sales agent.
        $this->postJson("/api/v1/clients/{$client->id}/calls", [
            'direction' => 'outbound',
            'next_action' => ['type' => 'call', 'due_date' => now()->addDay()->toDateString()],
        ])->assertCreated();

        $this->assertSame($salesAgent->id, NextAction::query()->pending()
            ->where('subject_type', 'client')->where('subject_id', $client->id)->value('assigned_to'));
    }

    public function test_an_in_site_visit_next_action_accepts_only_field_agents(): void
    {
        // No assignee is fine (the plan goes to the dispatch pool) — but a NAMED
        // assignee must be an is_agent user.
        $client = Client::factory()->create();
        $nonAgent = $this->userWithPermissions(['clients.view']); // role not is_agent
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'calls.log']));

        $this->postJson("/api/v1/clients/{$client->id}/calls", [
            'direction' => 'outbound',
            'next_action' => ['type' => 'in_site_visit', 'due_date' => now()->addDay()->toDateString(), 'assigned_to' => $nonAgent->id],
        ])->assertStatus(422)->assertJsonValidationErrorFor('next_action.assigned_to');

        $this->assertSame(0, NextAction::count());
    }

    public function test_next_action_time_is_optional_and_composed_into_due_at(): void
    {
        $agent = $this->agent();
        $client = Client::factory()->create();
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'calls.log']));

        // Date only → time defaults to start of day.
        $this->postJson("/api/v1/clients/{$client->id}/calls", [
            'direction' => 'outbound',
            'next_action' => ['type' => 'call', 'due_date' => '2026-08-01', 'assigned_to' => $agent->id],
        ])->assertCreated();
        $this->assertSame('2026-08-01 00:00:00', NextAction::query()->latest('id')->value('due_at')->toDateTimeString());

        // Date + time → composed.
        $this->postJson("/api/v1/clients/{$client->id}/calls", [
            'direction' => 'outbound',
            'next_action' => ['type' => 'call', 'due_date' => '2026-08-02', 'due_time' => '14:30', 'assigned_to' => $agent->id],
        ])->assertCreated();
        $this->assertSame('2026-08-02 14:30:00', NextAction::query()->latest('id')->value('due_at')->toDateTimeString());
    }

    public function test_correcting_a_call_supersedes_the_original_and_keeps_both(): void
    {
        $agent = $this->agent();
        $client = Client::factory()->create();
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'calls.log']));

        $callId = $this->postJson("/api/v1/clients/{$client->id}/calls", [
            'direction' => 'outbound',
            'next_action' => $this->nextActionPayload($agent),
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/calls/{$callId}/correct", [
            'reason' => 'Wrong direction', 'direction' => 'inbound', 'notes' => 'Fixed',
        ])->assertSuccessful()
            ->assertJsonPath('data.direction', 'inbound')
            ->assertJsonPath('data.edited', true);

        // Original cancelled with the reason; the new version links back via supersedes_id.
        $this->assertDatabaseHas('calls', ['id' => $callId, 'status' => 'cancelled', 'cancellation_reason' => 'Wrong direction']);
        $this->assertDatabaseHas('calls', ['supersedes_id' => $callId, 'status' => 'active', 'direction' => 'inbound']);
        // The edit is audited as a "duplicate" on the append-only activity log.
        $this->assertDatabaseHas('activity_log', ['subject_type' => Call::class, 'action' => 'duplicate']);
    }

    public function test_correcting_a_next_action_changes_type_and_keeps_one_pending(): void
    {
        $sales = $this->agent();
        $fieldAgent = $this->agent();
        $client = Client::factory()->create(['assigned_agent_id' => $sales->id]);
        $unit = Unit::factory()->create();
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'calls.log']));

        // Qualify with a property so the deal + shortlist exist (an in-site plan
        // needs shortlisted units to materialize field visits from).
        $this->postJson("/api/v1/clients/{$client->id}/calls", [
            'direction' => 'outbound',
            'properties' => [['shortlistable_type' => 'unit', 'shortlistable_id' => $unit->id]],
            'next_action' => ['type' => 'call', 'due_date' => now()->addDay()->toDateString()],
        ])->assertCreated();
        $project = ClientProject::query()->where('client_id', $client->id)->sole();
        $na = NextAction::query()->active()->pending()->firstOrFail();

        // Change the plan: a follow-up call becomes an in-site visit for a field agent.
        $this->postJson("/api/v1/next-actions/{$na->id}/correct", [
            'reason' => 'Client asked to visit', 'type' => 'in_site_visit',
            'due_date' => now()->addDays(3)->toDateString(), 'assigned_to' => $fieldAgent->id,
        ])->assertSuccessful()->assertJsonPath('data.type', 'in_site_visit');

        $this->assertDatabaseHas('next_actions', ['id' => $na->id, 'status' => 'cancelled']);
        $this->assertSame(1, NextAction::query()->active()->pending()
            ->where('subject_type', 'client_project')->where('subject_id', $project->id)->count());
    }

    public function test_completing_an_office_visit_with_in_site_next_generates_field_visits(): void
    {
        $fieldAgent = $this->agent();
        $client = Client::factory()->create();
        $project = ClientProject::factory()->create(['client_id' => $client->id]);
        $unitA = Unit::factory()->create();
        $unitB = Unit::factory()->create();
        foreach ([$unitA, $unitB] as $u) {
            ShortlistItem::factory()->create([
                'client_project_id' => $project->id, 'shortlistable_type' => 'unit', 'shortlistable_id' => $u->id,
            ]);
        }
        $office = Visit::factory()->create(['client_id' => $client->id, 'client_project_id' => $project->id, 'type' => 'office']);
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'visits.conduct']));

        $this->postJson("/api/v1/visits/{$office->id}/complete", [
            'next_action' => ['type' => 'in_site_visit', 'due_date' => now()->addDay()->toDateString(), 'assigned_to' => $fieldAgent->id],
        ])->assertOk();

        // One pending in-site visit per shortlisted unit, assigned to the field agent.
        $this->assertSame(2, Visit::query()->where('type', 'in_site')->where('client_project_id', $project->id)
            ->whereNull('completed_at')->where('agent_id', $fieldAgent->id)->count());
    }

    public function test_completing_a_deal_office_visit_without_a_shortlist_is_rejected(): void
    {
        $agent = $this->agent();
        $client = Client::factory()->create();
        $project = ClientProject::factory()->create(['client_id' => $client->id]);
        $office = Visit::factory()->create(['client_id' => $client->id, 'client_project_id' => $project->id, 'type' => 'office']);
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'visits.conduct']));

        $this->postJson("/api/v1/visits/{$office->id}/complete", [
            'next_action' => $this->nextActionPayload($agent),
        ])->assertStatus(422);

        // The completion rolled back with the rule.
        $this->assertNull($office->fresh()->completed_at);
    }

    public function test_completing_an_office_visit_syncs_the_shortlist_payload(): void
    {
        $agent = $this->agent();
        $client = Client::factory()->create();
        $project = ClientProject::factory()->create(['client_id' => $client->id]);
        $unit = Unit::factory()->create();
        $office = Visit::factory()->create(['client_id' => $client->id, 'client_project_id' => $project->id, 'type' => 'office']);
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'visits.conduct']));

        $this->postJson("/api/v1/visits/{$office->id}/complete", [
            'shortlist' => [['shortlistable_type' => 'unit', 'shortlistable_id' => $unit->id]],
            'next_action' => $this->nextActionPayload($agent),
        ])->assertOk();

        $this->assertNotNull($office->fresh()->completed_at);
        $this->assertDatabaseHas('shortlist_items', [
            'client_project_id' => $project->id, 'office_visit_id' => $office->id,
            'shortlistable_type' => 'unit', 'shortlistable_id' => $unit->id, 'status' => 'active',
        ]);
    }

    public function test_emptying_the_shortlist_on_office_completion_is_rejected_atomically(): void
    {
        $agent = $this->agent();
        $client = Client::factory()->create();
        $project = ClientProject::factory()->create(['client_id' => $client->id]);
        $unit = Unit::factory()->create();
        $item = ShortlistItem::factory()->create([
            'client_project_id' => $project->id, 'shortlistable_type' => 'unit', 'shortlistable_id' => $unit->id,
        ]);
        $office = Visit::factory()->create(['client_id' => $client->id, 'client_project_id' => $project->id, 'type' => 'office']);
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'visits.conduct']));

        $this->postJson("/api/v1/visits/{$office->id}/complete", [
            'shortlist' => [],
            'next_action' => $this->nextActionPayload($agent),
        ])->assertStatus(422);

        // Nothing persisted: the visit stays open and the item stays active.
        $this->assertNull($office->fresh()->completed_at);
        $this->assertDatabaseHas('shortlist_items', ['id' => $item->id, 'status' => 'active']);
    }

    public function test_completing_an_in_site_visit_advances_the_shortlisted_property(): void
    {
        $agent = $this->agent();
        $client = Client::factory()->create();
        $project = ClientProject::factory()->create(['client_id' => $client->id]);
        $unit = Unit::factory()->create();
        $item = ShortlistItem::factory()->create([
            'client_project_id' => $project->id, 'shortlistable_type' => 'unit', 'shortlistable_id' => $unit->id,
        ]);
        $list = DynamicList::create(['key' => 'insite_outcomes', 'name' => 'In-site', 'is_system' => false]);
        $interested = DynamicListItem::create([
            'dynamic_list_id' => $list->id, 'label' => 'Interested', 'value' => 'visited_interested', 'is_active' => true,
        ]);
        // In-site logs are completed by their assigned agent (Phase-5 access rule).
        $actor = $this->userWithPermissions(['clients.view', 'visits.conduct']);
        $visit = Visit::factory()->inSite()->create([
            'client_id' => $client->id, 'client_project_id' => $project->id,
            'unit_id' => $unit->id, 'agent_id' => $actor->id,
        ]);
        Sanctum::actingAs($actor);

        $this->postJson("/api/v1/visits/{$visit->id}/complete", [
            'outcome_id' => $interested->id,
            'next_action' => $this->nextActionPayload($agent),
        ])->assertOk();

        $this->assertSame('visited_interested', $item->fresh()->state->value);
    }

    public function test_a_visit_can_only_be_assigned_to_an_agent(): void
    {
        $client = Client::factory()->create();
        $nonAgent = $this->userWithPermissions(['clients.view']); // role not is_agent
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'visits.assign']));

        $this->postJson('/api/v1/visits', [
            'client_id' => $client->id, 'type' => 'office',
            'agent_id' => $nonAgent->id, 'scheduled_at' => now()->addDay()->toDateTimeString(),
        ])->assertStatus(422)->assertJsonValidationErrorFor('agent_id');
    }

    public function test_scheduling_an_in_site_visit_requires_a_unit(): void
    {
        $client = Client::factory()->create();
        $agent = $this->agent();
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'visits.assign']));

        $this->postJson('/api/v1/visits', [
            'client_id' => $client->id, 'type' => 'in_site',
            'agent_id' => $agent->id, 'scheduled_at' => now()->addDay()->toDateTimeString(),
        ])->assertStatus(422)->assertJsonValidationErrorFor('unit_id');
    }

    public function test_scheduling_a_valid_office_visit_works(): void
    {
        $client = Client::factory()->create();
        Call::factory()->create(['client_id' => $client->id]); // call-first rule
        $agent = $this->agent();
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'visits.assign']));

        $this->postJson('/api/v1/visits', [
            'client_id' => $client->id, 'type' => 'office',
            'agent_id' => $agent->id, 'scheduled_at' => now()->addDay()->toDateTimeString(),
        ])->assertCreated()->assertJsonPath('data.type', 'office');
    }

    public function test_reassigning_a_visit_to_a_non_agent_is_rejected(): void
    {
        $visit = Visit::factory()->create();
        $nonAgent = $this->userWithPermissions(['clients.view']);
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'visits.assign']));

        $this->postJson("/api/v1/visits/{$visit->id}/assign", ['agent_id' => $nonAgent->id])
            ->assertStatus(422);
    }

    public function test_completing_a_visit_without_a_next_action_is_allowed(): void
    {
        // Optional plan: a visit can close its thread — completed, no pending
        // action left (one can be planned later, standalone).
        $visit = Visit::factory()->create();
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'visits.conduct']));

        $this->postJson("/api/v1/visits/{$visit->id}/complete", [])
            ->assertOk()
            ->assertJsonPath('data.is_completed', true);

        $this->assertNotNull($visit->fresh()->completed_at);
        $this->assertSame(0, NextAction::count());
    }

    public function test_completing_a_visit_records_completion_and_a_next_action(): void
    {
        $agent = $this->agent();
        $actor = $this->userWithPermissions(['clients.view', 'visits.conduct']);
        $visit = Visit::factory()->inSite()->create(['agent_id' => $actor->id]);
        Sanctum::actingAs($actor);

        $this->postJson("/api/v1/visits/{$visit->id}/complete", [
            'next_action' => $this->nextActionPayload($agent),
        ])->assertOk()->assertJsonPath('data.is_completed', true);

        $this->assertNotNull($visit->fresh()->completed_at);
        $this->assertSame(1, NextAction::query()->pending()
            ->where('subject_type', 'client')->where('subject_id', $visit->client_id)->count());
    }

    public function test_an_in_site_visit_is_completed_only_by_its_agent_or_a_visit_admin(): void
    {
        $assigned = $this->userWithPermissions(['clients.view', 'visits.conduct']);
        $visit = Visit::factory()->inSite()->create(['agent_id' => $assigned->id]);
        $payload = fn () => ['next_action' => $this->nextActionPayload($this->agent())];

        // Another conducting user (not the assigned agent) is rejected.
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'visits.conduct']));
        $this->postJson("/api/v1/visits/{$visit->id}/complete", $payload())->assertForbidden();

        // A visit admin (visits.assign) may step in.
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'visits.conduct', 'visits.assign']));
        $this->postJson("/api/v1/visits/{$visit->id}/complete", $payload())->assertOk();

        // Office visits keep the plain visits.conduct rule.
        $office = Visit::factory()->create();
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'visits.conduct']));
        $this->postJson("/api/v1/visits/{$office->id}/complete", $payload())->assertOk();
    }

    public function test_the_assigned_agent_corrects_their_own_in_site_log_but_not_others(): void
    {
        $assigned = $this->userWithPermissions(['clients.view', 'visits.conduct']);
        $mine = Visit::factory()->inSite()->create(['agent_id' => $assigned->id]);
        $someoneElses = Visit::factory()->inSite()->create();
        $correction = fn (Visit $v) => [
            'reason' => 'Wrong slot', 'type' => 'in_site', 'unit_id' => $v->unit_id,
            'scheduled_at' => now()->addDays(2)->toDateTimeString(),
        ];

        Sanctum::actingAs($assigned);
        $this->postJson("/api/v1/visits/{$mine->id}/correct", $correction($mine))->assertSuccessful();
        $this->postJson("/api/v1/visits/{$someoneElses->id}/correct", $correction($someoneElses))->assertForbidden();
    }

    public function test_logging_a_call_rejects_a_project_from_another_client(): void
    {
        $client = Client::factory()->create();
        $otherProject = ClientProject::factory()->create(); // belongs to a different client
        $agent = $this->agent();
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'calls.log']));

        $this->postJson("/api/v1/clients/{$client->id}/calls", [
            'direction' => 'outbound',
            'client_project_id' => $otherProject->id,
            'next_action' => $this->nextActionPayload($agent),
        ])->assertStatus(422)->assertJsonValidationErrorFor('client_project_id');
    }

    public function test_scheduling_a_visit_rejects_a_project_from_another_client(): void
    {
        $client = Client::factory()->create();
        $otherProject = ClientProject::factory()->create();
        $agent = $this->agent();
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'visits.assign']));

        $this->postJson('/api/v1/visits', [
            'client_id' => $client->id, 'type' => 'office',
            'client_project_id' => $otherProject->id,
            'agent_id' => $agent->id, 'scheduled_at' => now()->addDay()->toDateTimeString(),
        ])->assertStatus(422)->assertJsonValidationErrorFor('client_project_id');
    }

    public function test_logging_a_call_requires_calls_log(): void
    {
        $client = Client::factory()->create();
        Sanctum::actingAs($this->userWithPermissions(['clients.view']));

        $this->postJson("/api/v1/clients/{$client->id}/calls", ['direction' => 'outbound'])
            ->assertForbidden();
    }

    public function test_scheduling_a_visit_requires_visits_assign(): void
    {
        $client = Client::factory()->create();
        $agent = $this->agent();
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'visits.conduct']));

        $this->postJson('/api/v1/visits', [
            'client_id' => $client->id, 'type' => 'office',
            'agent_id' => $agent->id, 'scheduled_at' => now()->addDay()->toDateTimeString(),
        ])->assertForbidden();
    }

    public function test_timeline_returns_calls_visits_and_open_actions(): void
    {
        $client = Client::factory()->create();
        $agent = $this->agent();
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'calls.log']));
        $this->postJson("/api/v1/clients/{$client->id}/calls", ['direction' => 'outbound', 'next_action' => $this->nextActionPayload($agent)])->assertCreated();
        Visit::factory()->create(['client_id' => $client->id]);

        // view_all: the timeline follows client visibility (a scoped user 404s —
        // covered in ReviewRegressionTest); here we test the payload shape.
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'clients.view_all']));
        $this->getJson("/api/v1/clients/{$client->id}/timeline")
            ->assertOk()
            ->assertJsonCount(1, 'data.calls')
            ->assertJsonCount(1, 'data.visits')
            ->assertJsonCount(1, 'data.next_actions');
    }
}
