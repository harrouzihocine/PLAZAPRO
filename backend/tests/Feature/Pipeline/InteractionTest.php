<?php

declare(strict_types=1);

namespace Tests\Feature\Pipeline;

use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Clients\Models\ShortlistItem;
use App\Modules\Collaboration\Notifications\DomainNotification;
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
use Illuminate\Support\Facades\Notification;
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

    public function test_a_call_must_have_a_next_action_or_a_closure(): void
    {
        // The self-closing rule: a concluded call never leaves the engagement
        // dangling — it either plans a next step or carries an explicit closure.
        $client = Client::factory()->create();
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'calls.log']));

        $this->postJson("/api/v1/clients/{$client->id}/calls", ['direction' => 'outbound'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('closure');

        $this->assertSame(0, NextAction::count());
    }

    public function test_logging_a_call_can_conclude_with_a_closure_instead_of_a_next_action(): void
    {
        // No next step: the client is put on the desire list (an explicit outcome,
        // not a dangling thread). No pending action is left.
        $client = Client::factory()->create();
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'calls.log']));

        $this->postJson("/api/v1/clients/{$client->id}/calls", [
            'direction' => 'outbound',
            'closure' => ['type' => 'desire', 'desire' => ['notes' => 'Wants a 3-room near the centre.']],
        ])->assertCreated();

        $this->assertDatabaseHas('calls', ['client_id' => $client->id, 'direction' => 'outbound']);
        $this->assertSame(0, NextAction::count());
        $this->assertDatabaseHas('desires', ['client_id' => $client->id]);
    }

    public function test_a_next_action_can_be_planned_after_the_fact(): void
    {
        $client = Client::factory()->create();
        $agent = $this->agent();
        // view_all: this test exercises the planning mechanics, not visibility
        // (ReviewRegressionTest covers the visibility rule). Logging the call
        // rides on calls.log; the standalone plan needs next_actions.plan.
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'clients.view_all', 'calls.log', 'next_actions.plan']));

        // A call concluded onto the desire list; a standalone plan arrives later.
        $this->postJson("/api/v1/clients/{$client->id}/calls", [
            'direction' => 'outbound',
            'closure' => ['type' => 'desire', 'desire' => ['notes' => 'Follow up when inventory arrives.']],
        ])->assertCreated();

        $this->postJson("/api/v1/clients/{$client->id}/next-actions", [
            'type' => 'call',
            'due_date' => now()->addDays(2)->toDateString(),
            'assigned_to' => $agent->id,
        ])->assertCreated();

        $this->assertSame(1, NextAction::query()->pending()
            ->where('subject_type', 'client')->where('subject_id', $client->id)->count());
    }

    public function test_standalone_planning_requires_next_actions_plan(): void
    {
        // calls.log alone no longer opens the "Plan next action" button — the
        // grant was split so it can be handed out person-by-person. (A next
        // action riding on a call log still works under calls.log.)
        $client = Client::factory()->create();
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'clients.view_all', 'calls.log']));

        $this->postJson("/api/v1/clients/{$client->id}/next-actions", [
            'type' => 'call',
            'due_date' => now()->addDays(2)->toDateString(),
        ])->assertForbidden();
    }

    public function test_an_archive_closure_archives_the_project_with_a_reason_and_note(): void
    {
        $client = Client::factory()->create();
        $project = ClientProject::factory()->create(['client_id' => $client->id]);
        $list = DynamicList::create(['key' => 'cancellation_reasons', 'name' => 'Reasons', 'is_system' => true]);
        $reason = DynamicListItem::create([
            'dynamic_list_id' => $list->id, 'label' => 'Changed mind', 'value' => 'changed_mind', 'is_active' => true,
        ]);
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'calls.log']));

        $this->postJson("/api/v1/clients/{$client->id}/calls", [
            'direction' => 'outbound',
            'client_project_id' => $project->id,
            'closure' => ['type' => 'archive', 'reason_id' => $reason->id, 'note' => 'Bought elsewhere.'],
        ])->assertCreated();

        $this->assertSame('archived', $project->fresh()->status->value);
        $this->assertStringContainsString('Changed mind', (string) $project->fresh()->cancellation_reason);
        $this->assertStringContainsString('Bought elsewhere', (string) $project->fresh()->cancellation_reason);
    }

    public function test_a_won_project_still_takes_new_calls_but_a_frozen_one_does_not(): void
    {
        // Won no longer freezes by itself — the client may buy another
        // apartment on the same project. Only an EXPLICIT freeze (frozen_at,
        // a projects.freeze act) closes it to new activity.
        $client = Client::factory()->create();
        $project = ClientProject::factory()->create(['client_id' => $client->id, 'stage' => 'won']);
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'calls.log']));

        $payload = [
            'direction' => 'outbound',
            'client_project_id' => $project->id,
            'next_action' => $this->nextActionPayload($this->agent()),
        ];

        $this->postJson("/api/v1/clients/{$client->id}/calls", $payload)->assertCreated();

        $project->update(['frozen_at' => now()]);

        $this->postJson("/api/v1/clients/{$client->id}/calls", $payload)->assertStatus(422);
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
        // Logging needs calls.log; correcting the rapport is its own grant.
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'calls.log', 'logs.edit_call']));

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
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'calls.log', 'logs.edit_next_action']));

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
            'reason_id' => $this->changeReasonId(), 'note' => 'Client asked to visit', 'type' => 'in_site_visit',
            'due_date' => now()->addDays(3)->toDateString(), 'assigned_to' => $fieldAgent->id,
        ])->assertSuccessful()->assertJsonPath('data.type', 'in_site_visit');

        $this->assertDatabaseHas('next_actions', ['id' => $na->id, 'status' => 'cancelled']);
        $this->assertSame(1, NextAction::query()->active()->pending()
            ->where('subject_type', 'client_project')->where('subject_id', $project->id)->count());
    }

    public function test_correcting_a_call_into_an_in_site_visit_without_an_assignee_pools_it(): void
    {
        // The reported case: a non-dispatcher edits a sales-owned call plan into
        // an in-site visit. They can't pick a field agent (the picker is theirs
        // only with visits.dispatch), so no assignee is sent. The inherited
        // sales owner is NOT a field agent — so the plan must land in the
        // dispatch pool, never be rejected as "must be an active agent".
        $nonAgentSales = $this->userWithPermissions(['clients.view']); // role not is_agent
        $client = Client::factory()->create(['assigned_agent_id' => $nonAgentSales->id]);
        $unit = Unit::factory()->create();
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'calls.log', 'logs.edit_next_action']));

        $this->postJson("/api/v1/clients/{$client->id}/calls", [
            'direction' => 'outbound',
            'properties' => [['shortlistable_type' => 'unit', 'shortlistable_id' => $unit->id]],
            'next_action' => ['type' => 'call', 'due_date' => now()->addDay()->toDateString()],
        ])->assertCreated();
        $na = NextAction::query()->active()->pending()->firstOrFail();
        $this->assertSame($nonAgentSales->id, $na->assigned_to); // inherited the sales owner

        // No assigned_to in the payload (the picker was hidden) — must succeed.
        $this->postJson("/api/v1/next-actions/{$na->id}/correct", [
            'reason_id' => $this->changeReasonId(), 'note' => 'Client asked to visit', 'type' => 'in_site_visit',
            'due_date' => now()->addDays(3)->toDateString(),
        ])->assertSuccessful()->assertJsonPath('data.type', 'in_site_visit');

        // The corrected plan is pooled (no assignee), held for a dispatcher.
        $this->assertNull(NextAction::query()->active()->pending()->firstOrFail()->assigned_to);
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
            'visited_at' => now()->subMinutes(30)->toDateTimeString(),
            'next_action' => $this->nextActionPayload($agent),
        ])->assertOk();

        $this->assertSame('visited_interested', $item->fresh()->state->value);
    }

    public function test_completing_a_visit_notifies_the_owner_and_dispatchers_but_not_the_completer(): void
    {
        Notification::fake();

        // The client belongs to a sales agent (owner); a dispatched FIELD agent
        // fills the in-site log on their behalf. The owner and the dispatchers
        // should hear that the log task is done — the completer should not.
        $owner = $this->agent();
        $dispatcher = $this->userWithPermissions(['visits.dispatch']);
        $actor = $this->userWithPermissions(['clients.view', 'visits.conduct']);

        $client = Client::factory()->create(['assigned_agent_id' => $owner->id]);
        $project = ClientProject::factory()->create(['client_id' => $client->id]);
        $unit = Unit::factory()->create();
        ShortlistItem::factory()->create([
            'client_project_id' => $project->id, 'shortlistable_type' => 'unit', 'shortlistable_id' => $unit->id,
        ]);
        $interested = $this->insiteOutcome('visited_interested');
        $visit = Visit::factory()->inSite()->create([
            'client_id' => $client->id, 'client_project_id' => $project->id,
            'unit_id' => $unit->id, 'agent_id' => $actor->id,
        ]);

        Sanctum::actingAs($actor);
        $this->postJson("/api/v1/visits/{$visit->id}/complete", [
            'outcome_id' => $interested->id,
            'visited_at' => now()->subMinutes(30)->toDateTimeString(),
            'next_action' => $this->nextActionPayload($owner),
        ])->assertOk();

        $isDone = fn ($n) => $n->kind === 'visit_completed';
        Notification::assertSentTo($owner, DomainNotification::class, $isDone);
        Notification::assertSentTo($dispatcher, DomainNotification::class, $isDone);
        Notification::assertNotSentTo($actor, DomainNotification::class, $isDone);
    }

    /** A next_action_change_reasons list item id — the reason a plan was corrected. */
    private function changeReasonId(string $label = 'Changed the type of next step'): int
    {
        $list = DynamicList::firstOrCreate(
            ['key' => 'next_action_change_reasons'],
            ['name' => 'Change Reasons', 'is_system' => true],
        );

        return DynamicListItem::create([
            'dynamic_list_id' => $list->id, 'label' => $label, 'value' => 'changed_type', 'is_active' => true,
        ])->id;
    }

    /** An in-site outcome list item (insite_outcomes) with the given value. */
    private function insiteOutcome(string $value): DynamicListItem
    {
        $list = DynamicList::firstOrCreate(
            ['key' => 'insite_outcomes'],
            ['name' => 'In-site', 'is_system' => false],
        );

        return DynamicListItem::create([
            'dynamic_list_id' => $list->id, 'label' => ucfirst($value), 'value' => $value, 'is_active' => true,
        ]);
    }

    public function test_an_interim_in_site_visit_records_its_result_without_a_conclusion(): void
    {
        $client = Client::factory()->create();
        $project = ClientProject::factory()->create(['client_id' => $client->id]);
        [$unitA, $unitB] = [Unit::factory()->create(), Unit::factory()->create()];
        foreach ([$unitA, $unitB] as $u) {
            ShortlistItem::factory()->create([
                'client_project_id' => $project->id, 'shortlistable_type' => 'unit', 'shortlistable_id' => $u->id,
            ]);
        }
        $notVisited = $this->insiteOutcome('not_visited');
        $actor = $this->userWithPermissions(['clients.view', 'visits.conduct']);
        $visitA = Visit::factory()->inSite()->create([
            'client_id' => $client->id, 'client_project_id' => $project->id, 'unit_id' => $unitA->id, 'agent_id' => $actor->id,
        ]);
        $visitB = Visit::factory()->inSite()->create([
            'client_id' => $client->id, 'client_project_id' => $project->id, 'unit_id' => $unitB->id, 'agent_id' => $actor->id,
        ]);
        Sanctum::actingAs($actor);

        // Sibling B is still open, so completing A needs NO conclusion.
        $this->postJson("/api/v1/visits/{$visitA->id}/complete", [
            'outcome_id' => $notVisited->id, 'notes' => 'Nobody home',
            'visited_at' => now()->subMinutes(30)->toDateTimeString(),
        ])->assertOk();

        $this->assertNotNull($visitA->fresh()->completed_at);
        $this->assertNull($visitB->fresh()->completed_at); // the sibling stays open
        // No conclusion applied, no plan opened, the project is untouched.
        $this->assertSame(0, NextAction::query()->where('subject_id', $project->id)->count());
        $this->assertSame('active', $project->fresh()->status->value);
    }

    public function test_the_last_in_site_visit_applies_the_conclusion(): void
    {
        $client = Client::factory()->create();
        $project = ClientProject::factory()->create(['client_id' => $client->id]);
        $unit = Unit::factory()->create();
        ShortlistItem::factory()->create([
            'client_project_id' => $project->id, 'shortlistable_type' => 'unit', 'shortlistable_id' => $unit->id,
        ]);
        $reason = DynamicListItem::create([
            'dynamic_list_id' => DynamicList::create(['key' => 'cancellation_reasons', 'name' => 'Reasons', 'is_system' => false])->id,
            'label' => 'Not interested', 'value' => 'not_interested', 'is_active' => true,
        ]);
        $actor = $this->userWithPermissions(['clients.view', 'visits.conduct']);
        $visit = Visit::factory()->inSite()->create([
            'client_id' => $client->id, 'client_project_id' => $project->id, 'unit_id' => $unit->id, 'agent_id' => $actor->id,
        ]);
        Sanctum::actingAs($actor);

        // The only open in-site visit → its archive closure is applied.
        $this->postJson("/api/v1/visits/{$visit->id}/complete", [
            'visited_at' => now()->subMinutes(30)->toDateTimeString(),
            'closure' => ['type' => 'archive', 'reason_id' => $reason->id, 'note' => 'Client walked away'],
        ])->assertOk();

        $this->assertSame('archived', $project->fresh()->status->value);
    }

    public function test_in_site_next_action_targeting_another_apartment_shortlists_and_visits_only_it(): void
    {
        $fieldAgent = $this->agent();
        $client = Client::factory()->create();
        $project = ClientProject::factory()->create(['client_id' => $client->id]);
        $current = Unit::factory()->create();
        $another = Unit::factory()->create();
        ShortlistItem::factory()->create([
            'client_project_id' => $project->id, 'shortlistable_type' => 'unit', 'shortlistable_id' => $current->id,
        ]);
        $actor = $this->userWithPermissions(['clients.view', 'visits.conduct']);
        $visit = Visit::factory()->inSite()->create([
            'client_id' => $client->id, 'client_project_id' => $project->id, 'unit_id' => $current->id, 'agent_id' => $actor->id,
        ]);
        Sanctum::actingAs($actor);

        $this->postJson("/api/v1/visits/{$visit->id}/complete", [
            'visited_at' => now()->subMinutes(30)->toDateTimeString(),
            'next_action' => [
                'type' => 'in_site_visit', 'due_date' => now()->addDay()->toDateString(),
                'assigned_to' => $fieldAgent->id, 'unit_ids' => [$another->id],
            ],
        ])->assertOk();

        // The picked apartment joins the shortlist and gets its own field visit…
        $this->assertDatabaseHas('shortlist_items', [
            'client_project_id' => $project->id, 'shortlistable_type' => 'unit',
            'shortlistable_id' => $another->id, 'status' => 'active',
        ]);
        $this->assertSame(1, Visit::query()->where('type', 'in_site')->where('unit_id', $another->id)
            ->whereNull('completed_at')->where('agent_id', $fieldAgent->id)->count());
        // …and NO fresh visit is generated for the just-completed apartment.
        $this->assertSame(0, Visit::query()->where('type', 'in_site')->where('unit_id', $current->id)
            ->whereNull('completed_at')->count());
    }

    public function test_in_site_next_action_targeting_the_same_apartment_rearms_and_revisits_it(): void
    {
        $fieldAgent = $this->agent();
        $client = Client::factory()->create();
        $project = ClientProject::factory()->create(['client_id' => $client->id]);
        $unit = Unit::factory()->create();
        $item = ShortlistItem::factory()->create([
            'client_project_id' => $project->id, 'shortlistable_type' => 'unit', 'shortlistable_id' => $unit->id,
        ]);
        $interested = $this->insiteOutcome('visited_interested');
        $actor = $this->userWithPermissions(['clients.view', 'visits.conduct']);
        $visit = Visit::factory()->inSite()->create([
            'client_id' => $client->id, 'client_project_id' => $project->id, 'unit_id' => $unit->id, 'agent_id' => $actor->id,
        ]);
        Sanctum::actingAs($actor);

        $this->postJson("/api/v1/visits/{$visit->id}/complete", [
            'outcome_id' => $interested->id,
            'visited_at' => now()->subMinutes(30)->toDateTimeString(),
            'next_action' => [
                'type' => 'in_site_visit', 'due_date' => now()->addDay()->toDateString(),
                'assigned_to' => $fieldAgent->id, 'unit_ids' => [$unit->id],
            ],
        ])->assertOk();

        // The visited apartment is re-armed and gets a fresh second-look visit.
        $this->assertSame('not_visited', $item->fresh()->state->value);
        $this->assertSame(1, Visit::query()->where('type', 'in_site')->where('unit_id', $unit->id)
            ->whereNull('completed_at')->where('agent_id', $fieldAgent->id)->count());
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

    public function test_completing_a_visit_can_conclude_with_a_closure_instead_of_a_next_action(): void
    {
        // A visit that plans no next step must resolve into an explicit outcome
        // (here, the desire list) — no dangling thread, no pending action left.
        $visit = Visit::factory()->create();
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'visits.conduct']));

        $this->postJson("/api/v1/visits/{$visit->id}/complete", [
            'closure' => ['type' => 'desire', 'desire' => ['notes' => 'Prefers a higher floor.']],
        ])
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

        $visitedAt = now()->subHours(2)->startOfMinute();
        $this->postJson("/api/v1/visits/{$visit->id}/complete", [
            'visited_at' => $visitedAt->toDateTimeString(),
            'next_action' => $this->nextActionPayload($agent),
        ])->assertOk()->assertJsonPath('data.is_completed', true);

        // completed_at = when the log was filled; visited_at = the agent-stated
        // actual visit moment — both recorded, independently.
        $this->assertNotNull($visit->fresh()->completed_at);
        $this->assertTrue($visitedAt->equalTo($visit->fresh()->visited_at));
        $this->assertSame(1, NextAction::query()->pending()
            ->where('subject_type', 'client')->where('subject_id', $visit->client_id)->count());
    }

    public function test_an_in_site_completion_must_state_when_the_visit_happened(): void
    {
        $actor = $this->userWithPermissions(['clients.view', 'visits.conduct']);
        $visit = Visit::factory()->inSite()->create(['agent_id' => $actor->id]);
        Sanctum::actingAs($actor);

        // Missing → rejected; far-future (beyond clock-skew grace) → rejected.
        $this->postJson("/api/v1/visits/{$visit->id}/complete", [
            'next_action' => $this->nextActionPayload($this->agent()),
        ])->assertStatus(422)->assertJsonValidationErrors('visited_at');

        $this->postJson("/api/v1/visits/{$visit->id}/complete", [
            'visited_at' => now()->addHours(3)->toDateTimeString(),
            'next_action' => $this->nextActionPayload($this->agent()),
        ])->assertStatus(422)->assertJsonValidationErrors('visited_at');

        $this->assertNull($visit->fresh()->completed_at);
    }

    public function test_an_in_site_visit_is_completed_only_by_its_agent_or_a_visit_admin(): void
    {
        $assigned = $this->userWithPermissions(['clients.view', 'visits.conduct']);
        $visit = Visit::factory()->inSite()->create(['agent_id' => $assigned->id]);
        $payload = fn () => [
            'visited_at' => now()->subMinutes(30)->toDateTimeString(),
            'next_action' => $this->nextActionPayload($this->agent()),
        ];

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
