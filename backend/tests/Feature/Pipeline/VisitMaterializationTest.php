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

/**
 * Choosing a visit-type next step IS the scheduling step: the pending visit(s)
 * are materialized from the next action (office → one visit; in-site → one per
 * shortlisted unit), and corrections retire the superseded plan's pending visits.
 */
class VisitMaterializationTest extends TestCase
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

    /** A field agent (is_agent role) who can also conduct visits. */
    private function conductingFieldAgent(): User
    {
        $role = Role::factory()->agent()->create();
        $ids = collect(['clients.view', 'visits.conduct'])->map(
            fn (string $slug) => Permission::firstOrCreate(['slug' => $slug], ['name' => $slug])->id
        );
        $role->permissions()->sync($ids);

        return User::factory()->create(['role_id' => $role->id]);
    }

    public function test_an_office_visit_next_action_creates_the_pending_office_visit(): void
    {
        $sales = $this->agent();
        $client = Client::factory()->create(['assigned_agent_id' => $sales->id]);
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'calls.log']));

        $this->postJson("/api/v1/clients/{$client->id}/calls", [
            'direction' => 'outbound',
            'next_action' => ['type' => 'office_visit', 'due_date' => '2026-08-01', 'due_time' => '10:30'],
        ])->assertCreated();

        $na = NextAction::query()->active()->pending()->sole();
        $visit = Visit::query()->active()->sole();
        $this->assertSame('office', $visit->type->value);
        $this->assertSame($client->id, $visit->client_id);
        $this->assertSame($na->id, $visit->next_action_id);
        // Agent defaults to the client's sales agent; when = the plan's date+time.
        $this->assertSame($sales->id, $visit->agent_id);
        $this->assertSame('2026-08-01 10:30:00', $visit->scheduled_at->toDateTimeString());
    }

    public function test_a_second_office_visit_plan_replans_the_open_visit_instead_of_stacking(): void
    {
        $sales = $this->agent();
        $client = Client::factory()->create(['assigned_agent_id' => $sales->id]);
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'calls.log']));

        $post = fn (string $date) => $this->postJson("/api/v1/clients/{$client->id}/calls", [
            'direction' => 'outbound',
            'next_action' => ['type' => 'office_visit', 'due_date' => $date],
        ])->assertCreated();

        $post('2026-08-01');
        $post('2026-08-05'); // client rescheduled on a later call

        $visit = Visit::query()->active()->whereNull('completed_at')->sole();
        $this->assertSame('2026-08-05 00:00:00', $visit->scheduled_at->toDateTimeString());
    }

    public function test_an_in_site_next_action_generates_one_visit_per_shortlisted_unit(): void
    {
        $fieldAgent = $this->agent();
        $client = Client::factory()->create();
        $unitA = Unit::factory()->create();
        $unitB = Unit::factory()->create();
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'calls.log']));

        $this->postJson("/api/v1/clients/{$client->id}/calls", [
            'direction' => 'inbound',
            'properties' => [
                ['shortlistable_type' => 'unit', 'shortlistable_id' => $unitA->id],
                ['shortlistable_type' => 'unit', 'shortlistable_id' => $unitB->id],
            ],
            'next_action' => ['type' => 'in_site_visit', 'due_date' => now()->addDays(2)->toDateString(), 'assigned_to' => $fieldAgent->id],
        ])->assertCreated();

        $project = ClientProject::query()->where('client_id', $client->id)->sole();
        $na = NextAction::query()->active()->pending()->sole();
        $this->assertSame(2, Visit::query()->active()->where('type', 'in_site')
            ->where('client_project_id', $project->id)->whereNull('completed_at')
            ->where('agent_id', $fieldAgent->id)->where('next_action_id', $na->id)->count());
    }

    public function test_an_in_site_next_action_without_a_shortlist_is_rejected_and_rolls_back(): void
    {
        $fieldAgent = $this->agent();
        $client = Client::factory()->create();
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'calls.log']));

        $this->postJson("/api/v1/clients/{$client->id}/calls", [
            'direction' => 'outbound',
            'next_action' => ['type' => 'in_site_visit', 'due_date' => now()->addDay()->toDateString(), 'assigned_to' => $fieldAgent->id],
        ])->assertStatus(422);

        // The whole call rolled back — pipeline state is untouched.
        $this->assertSame(0, Call::count());
        $this->assertSame(0, NextAction::count());
        $this->assertSame(0, Visit::count());
    }

    public function test_correcting_a_call_plan_into_an_office_visit_creates_the_visit(): void
    {
        $sales = $this->agent();
        $client = Client::factory()->create(['assigned_agent_id' => $sales->id]);
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'calls.log', 'logs.edit_next_action']));

        $this->postJson("/api/v1/clients/{$client->id}/calls", [
            'direction' => 'outbound',
            'next_action' => ['type' => 'call', 'due_date' => now()->addDay()->toDateString()],
        ])->assertCreated();
        $na = NextAction::query()->active()->pending()->sole();
        $this->assertSame(0, Visit::count());

        $this->postJson("/api/v1/next-actions/{$na->id}/correct", [
            'reason_id' => $this->changeReasonId(), 'note' => 'Client wants to come in', 'type' => 'office_visit',
            'due_date' => now()->addDays(2)->toDateString(),
        ])->assertSuccessful();

        $corrected = NextAction::query()->active()->pending()->sole();
        $visit = Visit::query()->active()->whereNull('completed_at')->sole();
        $this->assertSame('office', $visit->type->value);
        $this->assertSame($corrected->id, $visit->next_action_id);
    }

    public function test_correcting_a_visit_plan_back_to_a_call_cancels_the_pending_visit(): void
    {
        $sales = $this->agent();
        $client = Client::factory()->create(['assigned_agent_id' => $sales->id]);
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'calls.log', 'logs.edit_next_action']));

        $this->postJson("/api/v1/clients/{$client->id}/calls", [
            'direction' => 'outbound',
            'next_action' => ['type' => 'office_visit', 'due_date' => now()->addDay()->toDateString()],
        ])->assertCreated();
        $na = NextAction::query()->active()->pending()->sole();
        $visit = Visit::query()->active()->sole();

        $this->postJson("/api/v1/next-actions/{$na->id}/correct", [
            'reason_id' => $this->changeReasonId('Prefers a phone follow-up'), 'type' => 'call',
            'due_date' => now()->addDays(2)->toDateString(),
        ])->assertSuccessful();

        // The planned visit is retired with the correction reason, kept in history.
        $this->assertDatabaseHas('visits', [
            'id' => $visit->id, 'status' => 'cancelled', 'cancellation_reason' => 'Prefers a phone follow-up',
        ]);
        $this->assertSame(0, Visit::query()->active()->whereNull('completed_at')->count());
    }

    public function test_completing_one_in_site_visit_does_not_cancel_its_pending_siblings(): void
    {
        $fieldAgent = $this->conductingFieldAgent();
        $client = Client::factory()->create();
        $project = ClientProject::factory()->create(['client_id' => $client->id]);
        $unitA = Unit::factory()->create();
        $unitB = Unit::factory()->create();
        foreach ([$unitA, $unitB] as $u) {
            ShortlistItem::factory()->create([
                'client_project_id' => $project->id, 'shortlistable_type' => 'unit', 'shortlistable_id' => $u->id,
            ]);
        }
        $na = NextAction::factory()->create([
            'subject_type' => 'client_project', 'subject_id' => $project->id,
            'type' => 'in_site_visit', 'assigned_to' => $fieldAgent->id,
        ]);
        $mkVisit = fn (Unit $u) => Visit::factory()->inSite()->create([
            'client_id' => $client->id, 'client_project_id' => $project->id,
            'unit_id' => $u->id, 'agent_id' => $fieldAgent->id, 'next_action_id' => $na->id,
        ]);
        $visitA = $mkVisit($unitA);
        $visitB = $mkVisit($unitB);
        Sanctum::actingAs($fieldAgent); // the assigned agent completes his own visit

        $this->postJson("/api/v1/visits/{$visitA->id}/complete", [
            'visited_at' => now()->subMinutes(30)->toDateTimeString(),
            'next_action' => ['type' => 'call', 'due_date' => now()->addDay()->toDateString(), 'assigned_to' => $fieldAgent->id],
        ])->assertOk();

        // Sibling B stays pending: prior plans are closed as done, never cancelled.
        $this->assertDatabaseHas('visits', ['id' => $visitB->id, 'status' => 'active']);
        $this->assertNull($visitB->fresh()->completed_at);
    }
}
