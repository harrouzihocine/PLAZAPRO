<?php

declare(strict_types=1);

namespace Tests\Feature\Pipeline;

use App\Modules\Clients\Models\Client;
use App\Modules\Collaboration\Notifications\DomainNotification;
use App\Modules\Pipeline\Enums\NextActionApproval;
use App\Modules\Pipeline\Enums\NextActionState;
use App\Modules\Pipeline\Models\NextAction;
use App\Modules\Pipeline\Models\Visit;
use App\Modules\Settings\Models\AppSetting;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The office-visit window: a plan within office_visit_max_days is business as
 * usual; beyond it the plan is created flagged approval_status=pending, kept
 * quiet, and the visits.dispatch holders decide — approve (announce), deny
 * (cancel, the agent replans) or reschedule (supersede with their own date).
 * Dispatchers' own plans are exempt.
 */
class OfficeVisitApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The static settings cache survives across tests in one process.
        AppSetting::flushRemembered();
        AppSetting::set('office_visit_max_days', '1');
    }

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

    private function planner(): User
    {
        return $this->userWithPermissions(['clients.view', 'calls.log', 'next_actions.plan']);
    }

    private function dispatcher(): User
    {
        return $this->userWithPermissions(['clients.view', 'next_actions.plan', 'visits.dispatch']);
    }

    /** Plan an office visit on a bare client, due in $days days. */
    private function planOfficeVisit(Client $client, int $days, ?string $time = null): TestResponse
    {
        return $this->postJson("/api/v1/clients/{$client->id}/next-actions", [
            'type' => 'office_visit',
            'due_date' => now()->addDays($days)->toDateString(),
            'due_time' => $time,
        ]);
    }

    public function test_an_in_window_office_visit_needs_no_approval(): void
    {
        Notification::fake();
        $planner = $this->planner();
        $client = Client::factory()->create(['assigned_agent_id' => $planner->id]);
        Sanctum::actingAs($planner);

        $this->planOfficeVisit($client, 1)->assertCreated();

        $na = NextAction::query()->active()->pending()->sole();
        $this->assertNull($na->approval_status);
        $this->assertNull($na->approval_requested_by);

        Notification::assertNotSentTo(
            User::all(),
            DomainNotification::class,
            fn (DomainNotification $n) => $n->kind === 'office_visit_approval',
        );
    }

    public function test_a_beyond_window_plan_waits_for_approval_and_stays_quiet(): void
    {
        Notification::fake();
        $planner = $this->planner();
        $dispatcher = $this->dispatcher();
        $overseer = $this->userWithPermissions(['oversight.pipeline']);
        $client = Client::factory()->create(['assigned_agent_id' => $planner->id]);
        Sanctum::actingAs($planner);

        $this->planOfficeVisit($client, 5, '10:00')
            ->assertCreated()
            ->assertJsonPath('data.approval_status', 'pending');

        $na = NextAction::query()->active()->pending()->sole();
        $this->assertSame(NextActionApproval::Pending, $na->approval_status);
        $this->assertEquals($planner->id, $na->approval_requested_by);

        // The visit exists (the manager sees it on the program grid)…
        $visit = Visit::query()->active()->sole();
        $this->assertSame($na->id, $visit->next_action_id);

        // …the dispatchers were asked…
        Notification::assertSentTo(
            $dispatcher,
            DomainNotification::class,
            fn (DomainNotification $n) => $n->kind === 'office_visit_approval',
        );

        // …and the normal announce is held back until the verdict.
        Notification::assertNotSentTo(
            $overseer,
            DomainNotification::class,
            fn (DomainNotification $n) => $n->kind === 'office_visit_scheduled',
        );
    }

    public function test_a_dispatchers_own_far_plan_is_exempt(): void
    {
        Notification::fake();
        $dispatcher = $this->dispatcher();
        $client = Client::factory()->create(['assigned_agent_id' => $dispatcher->id]);
        Sanctum::actingAs($dispatcher);

        $this->planOfficeVisit($client, 10)->assertCreated();

        $this->assertNull(NextAction::query()->active()->pending()->sole()->approval_status);
    }

    public function test_approve_confirms_the_plan_and_announces_the_visit(): void
    {
        $planner = $this->planner();
        $dispatcher = $this->dispatcher();
        $overseer = $this->userWithPermissions(['oversight.pipeline']);
        $client = Client::factory()->create(['assigned_agent_id' => $planner->id]);

        Sanctum::actingAs($planner);
        $this->planOfficeVisit($client, 5)->assertCreated();
        $na = NextAction::query()->active()->pending()->sole();

        Notification::fake();
        Sanctum::actingAs($dispatcher);
        $this->postJson("/api/v1/next-actions/{$na->id}/approval", ['decision' => 'approve'])
            ->assertOk()
            ->assertJsonPath('data.approval_status', 'approved');

        $na->refresh();
        $this->assertSame(NextActionApproval::Approved, $na->approval_status);
        $this->assertEquals($dispatcher->id, $na->approval_decided_by);
        $this->assertNotNull($na->approval_decided_at);

        // The held-back announce goes out now (pipeline overseers included),
        // and the requester hears the verdict.
        Notification::assertSentTo(
            $overseer,
            DomainNotification::class,
            fn (DomainNotification $n) => $n->kind === 'office_visit_scheduled',
        );
        Notification::assertSentTo(
            $planner,
            DomainNotification::class,
            fn (DomainNotification $n) => $n->kind === 'office_visit_approved',
        );
    }

    public function test_deny_cancels_the_plan_and_its_visit_with_the_reason(): void
    {
        $planner = $this->planner();
        $dispatcher = $this->dispatcher();
        $client = Client::factory()->create(['assigned_agent_id' => $planner->id]);

        Sanctum::actingAs($planner);
        $this->planOfficeVisit($client, 5)->assertCreated();
        $na = NextAction::query()->active()->pending()->sole();
        $visit = Visit::query()->active()->sole();

        Notification::fake();
        Sanctum::actingAs($dispatcher);

        // A deny without a reason is rejected — the reason rides the notification.
        $this->postJson("/api/v1/next-actions/{$na->id}/approval", ['decision' => 'deny'])
            ->assertUnprocessable();

        $this->postJson("/api/v1/next-actions/{$na->id}/approval", [
            'decision' => 'deny',
            'reason' => 'Too far out — client interest cools',
        ])->assertOk();

        $na->refresh();
        $visit->refresh();
        $this->assertSame(NextActionApproval::Denied, $na->approval_status);
        $this->assertTrue($na->isCancelled());
        $this->assertTrue($visit->isCancelled());
        $this->assertSame(0, NextAction::query()->active()->pending()->count());

        Notification::assertSentTo(
            $planner,
            DomainNotification::class,
            fn (DomainNotification $n) => $n->kind === 'office_visit_denied',
        );
    }

    public function test_reschedule_supersedes_with_the_managers_date(): void
    {
        $planner = $this->planner();
        $dispatcher = $this->dispatcher();
        $client = Client::factory()->create(['assigned_agent_id' => $planner->id]);

        Sanctum::actingAs($planner);
        $this->planOfficeVisit($client, 5)->assertCreated();
        $na = NextAction::query()->active()->pending()->sole();

        Notification::fake();
        Sanctum::actingAs($dispatcher);
        $newDate = now()->addDay()->toDateString();
        // 201: the reschedule creates the manager's own corrected plan.
        $this->postJson("/api/v1/next-actions/{$na->id}/approval", [
            'decision' => 'reschedule',
            'due_date' => $newDate,
            'due_time' => '11:30',
        ])->assertSuccessful();

        // The original keeps the request's fate; the corrected plan is the
        // dispatcher's own — pending state, no approval involved.
        $na->refresh();
        $this->assertSame(NextActionApproval::Rescheduled, $na->approval_status);
        $this->assertTrue($na->isCancelled());

        $corrected = NextAction::query()->active()->pending()->sole();
        $this->assertSame($na->id, $corrected->supersedes_id);
        $this->assertNull($corrected->approval_status);
        $this->assertSame($newDate.' 11:30', $corrected->due_at->format('Y-m-d H:i'));

        // The visit follows the new plan.
        $open = Visit::query()->active()->whereNull('completed_at')->sole();
        $this->assertSame($corrected->id, $open->next_action_id);

        Notification::assertSentTo(
            $planner,
            DomainNotification::class,
            fn (DomainNotification $n) => $n->kind === 'office_visit_rescheduled',
        );
    }

    public function test_only_dispatchers_decide_and_only_once(): void
    {
        $planner = $this->planner();
        $dispatcher = $this->dispatcher();
        $client = Client::factory()->create(['assigned_agent_id' => $planner->id]);

        Sanctum::actingAs($planner);
        $this->planOfficeVisit($client, 5)->assertCreated();
        $na = NextAction::query()->active()->pending()->sole();

        // The planner cannot decide their own request.
        $this->postJson("/api/v1/next-actions/{$na->id}/approval", ['decision' => 'approve'])
            ->assertForbidden();

        Sanctum::actingAs($dispatcher);
        $this->postJson("/api/v1/next-actions/{$na->id}/approval", ['decision' => 'approve'])->assertOk();

        // Already decided — a second verdict is rejected.
        $this->postJson("/api/v1/next-actions/{$na->id}/approval", ['decision' => 'approve'])
            ->assertUnprocessable();
    }

    public function test_the_program_endpoint_serves_the_week_and_the_pending_pool(): void
    {
        $planner = $this->planner();
        // Two clients: planning twice on one subject would close the first
        // plan (one-pending invariant) and re-schedule its visit.
        $today = Client::factory()->create(['assigned_agent_id' => $planner->id]);
        $far = Client::factory()->create(['assigned_agent_id' => $planner->id]);

        Sanctum::actingAs($planner);
        // Today = always inside the shown week; 5 days out = the pending pool.
        $this->planOfficeVisit($today, 0, '09:00')->assertCreated();
        $this->planOfficeVisit($far, 5)->assertCreated();

        // No grant → no program; the planner has neither page permission.
        $this->getJson('/api/v1/office-program')->assertForbidden();

        // oversight.pipeline alone no longer opens the page (it seeds the
        // split oversight.office_program onto its roles instead).
        Sanctum::actingAs($this->userWithPermissions(['oversight.pipeline']));
        $this->getJson('/api/v1/office-program')->assertForbidden();

        // Dispatchers are the managing audience: full names + the queue.
        Sanctum::actingAs($this->userWithPermissions(['visits.dispatch']));
        $data = $this->getJson('/api/v1/office-program')
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $data['window_days']);
        $this->assertNotEmpty($data['week_start']);
        $this->assertCount(1, $data['pending_approvals']);
        $this->assertSame($far->full_name, $data['pending_approvals'][0]['client']);

        $chips = collect($data['visits'])->where('client_id', $today->id);
        $this->assertTrue($chips->pluck('state')->contains('scheduled'));
        $this->assertFalse($chips->firstOrFail()['masked']);
        $this->assertSame($today->full_name, $chips->firstOrFail()['client']);
    }

    public function test_the_view_permission_gets_a_masked_grid_and_no_pending_pool(): void
    {
        $viewer = $this->userWithPermissions([
            'clients.view', 'calls.log', 'next_actions.plan', 'oversight.office_program',
        ]);
        $colleague = $this->planner();
        $mine = Client::factory()->create(['assigned_agent_id' => $viewer->id]);
        $theirs = Client::factory()->create(['assigned_agent_id' => $colleague->id]);
        $far = Client::factory()->create(['assigned_agent_id' => $colleague->id]);

        Sanctum::actingAs($viewer);
        $this->planOfficeVisit($mine, 0, '09:00')->assertCreated();
        Sanctum::actingAs($colleague);
        $this->planOfficeVisit($theirs, 0, '10:00')->assertCreated();
        $this->planOfficeVisit($far, 5)->assertCreated(); // pending request

        Sanctum::actingAs($viewer);
        $data = $this->getJson('/api/v1/office-program')->assertOk()->json('data');

        // The approval queue is management data — dispatchers only.
        $this->assertSame([], $data['pending_approvals']);

        // Own visit in the clear; every colleague slot is booked-anonymous:
        // no client name, no ids to link out with.
        $visits = collect($data['visits']);
        $own = $visits->first(fn ($v) => ! $v['masked']);
        $this->assertNotNull($own);
        $this->assertSame($mine->full_name, $own['client']);
        $this->assertSame($mine->id, $own['client_id']);

        $others = $visits->filter(fn ($v) => $v['masked']);
        $this->assertNotEmpty($others);
        foreach ($others as $chip) {
            $this->assertNull($chip['client']);
            $this->assertNull($chip['client_id']);
            $this->assertNull($chip['project_id']);
            $this->assertNotNull($chip['agent']); // the slot still names the colleague
        }
        // Exactly one chip is the viewer's own.
        $this->assertCount($visits->count() - 1, $others);
    }

    public function test_closing_a_pending_request_retracts_its_quiet_visit(): void
    {
        $planner = $this->planner();
        $dispatcher = $this->dispatcher();
        $client = Client::factory()->create(['assigned_agent_id' => $planner->id]);

        Sanctum::actingAs($planner);
        $this->planOfficeVisit($client, 5)->assertCreated();
        $request = NextAction::query()->active()->pending()->sole();
        $quiet = Visit::query()->active()->sole();

        // A later CALL plan replaces the request (ClosePendingNextActions):
        // the never-announced visit must not linger as an undecidable amber
        // ghost on the program grid.
        $this->postJson("/api/v1/clients/{$client->id}/next-actions", [
            'type' => 'call',
            'due_date' => now()->addDay()->toDateString(),
        ])->assertCreated();

        $request->refresh();
        $quiet->refresh();
        $this->assertSame(NextActionState::Done, $request->state);
        $this->assertTrue($quiet->isCancelled());

        Sanctum::actingAs($dispatcher);
        $data = $this->getJson('/api/v1/office-program')->assertOk()->json('data');
        $this->assertSame([], $data['pending_approvals']);
        $this->assertSame(
            [],
            collect($data['visits'])->where('state', 'awaiting_approval')->all(),
        );
    }

    public function test_replanning_in_window_replaces_the_quiet_visit_and_announces(): void
    {
        $planner = $this->planner();
        $overseer = $this->userWithPermissions(['oversight.pipeline']);
        $client = Client::factory()->create(['assigned_agent_id' => $planner->id]);

        Sanctum::actingAs($planner);
        $this->planOfficeVisit($client, 5)->assertCreated();
        $quiet = Visit::query()->active()->sole();

        // The agent thinks better of it and replans inside the window: the
        // request evaporates, and the NEW visit must be announced — it is a
        // confirmed appointment, not a muted request any more.
        Notification::fake();
        $this->planOfficeVisit($client, 1, '09:00')->assertCreated();

        $this->assertTrue($quiet->refresh()->isCancelled());
        $fresh = Visit::query()->active()->whereNull('completed_at')->sole();
        $this->assertNotSame($quiet->id, $fresh->id);
        $this->assertNull(NextAction::query()->active()->pending()->sole()->approval_status);

        Notification::assertSentTo(
            $overseer,
            DomainNotification::class,
            fn (DomainNotification $n) => $n->kind === 'office_visit_scheduled',
        );
    }

    public function test_the_decision_and_program_inputs_are_validated(): void
    {
        $planner = $this->planner();
        $dispatcher = $this->dispatcher();
        $client = Client::factory()->create(['assigned_agent_id' => $planner->id]);

        Sanctum::actingAs($planner);
        $this->planOfficeVisit($client, 5)->assertCreated();
        $na = NextAction::query()->active()->pending()->sole();

        Sanctum::actingAs($dispatcher);

        // A malformed week must 422, never 500 (Carbon::parse on raw input).
        $this->getJson('/api/v1/office-program?week=garbage')->assertUnprocessable();
        $this->getJson('/api/v1/office-program?week[]=x')->assertUnprocessable();

        // The deny reason lands in a VARCHAR(255) with a prefix — cap enforced.
        $this->postJson("/api/v1/next-actions/{$na->id}/approval", [
            'decision' => 'deny',
            'reason' => str_repeat('x', 201),
        ])->assertUnprocessable();

        // A reschedule can never point into the past.
        $this->postJson("/api/v1/next-actions/{$na->id}/approval", [
            'decision' => 'reschedule',
            'due_date' => now()->subDay()->toDateString(),
        ])->assertUnprocessable();
    }

    public function test_superseding_resets_the_approval_trail_by_default(): void
    {
        $planner = $this->planner();
        $dispatcher = $this->dispatcher();
        $client = Client::factory()->create(['assigned_agent_id' => $planner->id]);

        Sanctum::actingAs($planner);
        $this->planOfficeVisit($client, 5)->assertCreated();
        $na = NextAction::query()->active()->pending()->sole();

        Sanctum::actingAs($dispatcher);
        $this->postJson("/api/v1/next-actions/{$na->id}/approval", ['decision' => 'approve'])->assertOk();

        // A future supersede path that never heard of approvals must not clone
        // the earned verdict onto the replacement — the model resets the trail
        // by default (callers that DO compute one pass it explicitly).
        $replacement = $na->refresh()->supersedeWith([], 'unrelated correction');
        $this->assertNull($replacement->approval_status);
        $this->assertNull($replacement->approval_requested_by);
        $this->assertNull($replacement->approval_decided_by);
        $this->assertNull($replacement->approval_decided_at);
    }

    public function test_the_window_setting_is_editable(): void
    {
        Sanctum::actingAs($this->userWithPermissions(['settings.manage']));

        $this->putJson('/api/v1/app-settings', ['office_visit_max_days' => 3])
            ->assertOk()
            ->assertJsonPath('data.office_visit_max_days', '3');

        $this->putJson('/api/v1/app-settings', ['office_visit_max_days' => -1])
            ->assertUnprocessable();
    }
}
