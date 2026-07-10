<?php

declare(strict_types=1);

namespace Tests\Feature\Pipeline;

use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Clients\Models\ShortlistItem;
use App\Modules\Collaboration\Notifications\DomainNotification;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Pipeline\Models\AgentPosition;
use App\Modules\Pipeline\Models\DutySession;
use App\Modules\Pipeline\Models\NextAction;
use App\Modules\Pipeline\Models\Visit;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The dispatch GPS layer: duty sessions, position ingest + geofence
 * arrival/departure, the agent's visit lifecycle (accept / en route /
 * arrived / decline-to-pool), reassignment resetting the clock, the ranked
 * assignment suggestions, the live map, My Day, and the watchdog sweeper.
 */
class DispatchGpsTest extends TestCase
{
    use RefreshDatabase;

    // Résidence coordinates (Algiers) and a fix ~700 m north — beyond the
    // default 200 m radius AND its 1.5× departure hysteresis.
    private const SITE_LAT = 36.7538;

    private const SITE_LNG = 3.0588;

    private const FAR_LAT = 36.7601;

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

    /** An assigned, open in-site visit on a pinned site, backed by a pending plan. */
    private function assignedVisit(User $agent, ?Location $site = null): Visit
    {
        $site ??= Location::factory()->create([
            'latitude' => self::SITE_LAT, 'longitude' => self::SITE_LNG,
        ]);
        $unit = Unit::factory()->for($site)->create();
        $project = ClientProject::factory()->create(['client_id' => Client::factory()->create()->id]);
        // A real in-site plan always rides a shortlisted unit — the pool
        // return (decline) re-syncs from the shortlist and 422s without one.
        ShortlistItem::factory()->create([
            'client_project_id' => $project->id,
            'shortlistable_type' => 'unit',
            'shortlistable_id' => $unit->id,
            'state' => 'shortlisted',
        ]);
        $action = NextAction::factory()->create([
            'subject_type' => 'client_project', 'subject_id' => $project->id,
            'type' => 'in_site_visit', 'state' => 'pending',
            'assigned_to' => $agent->id, 'due_at' => now()->addHours(2),
        ]);

        // 20:00 TODAY whatever the wall clock: the geofence/map/My Day logic
        // all scope to "today", and a now()+2h would cross midnight in a late
        // test run.
        return Visit::factory()->inSite()->create([
            'client_id' => $project->client_id, 'client_project_id' => $project->id,
            'unit_id' => $unit->id, 'agent_id' => $agent->id, 'next_action_id' => $action->id,
            'scheduled_at' => now()->startOfDay()->addHours(20), 'completed_at' => null,
        ]);
    }

    public function test_the_duty_toggle_opens_and_closes_a_session_and_colours_the_board(): void
    {
        $agent = $this->userWith([], isAgent: true);
        $dispatcher = $this->userWith(['visits.dispatch']);

        Sanctum::actingAs($agent);
        $this->postJson('/api/v1/me/duty', ['on' => true])->assertOk()->assertJsonPath('data.on', true);
        // Idempotent: toggling on again keeps the one open session.
        $this->postJson('/api/v1/me/duty', ['on' => true])->assertOk();
        $this->assertSame(1, DutySession::count());

        Sanctum::actingAs($dispatcher);
        $board = $this->getJson('/api/v1/dispatch/board')->assertOk()->json('data');
        $row = collect($board['agents'])->firstWhere('id', $agent->id);
        $this->assertSame('available', $row['status']);

        Sanctum::actingAs($agent);
        $this->postJson('/api/v1/me/duty', ['on' => false])->assertOk()->assertJsonPath('data.on', false);
        $this->assertNotNull(DutySession::first()->ended_at);

        Sanctum::actingAs($dispatcher);
        $board = $this->getJson('/api/v1/dispatch/board')->assertOk()->json('data');
        $this->assertSame('off_duty', collect($board['agents'])->firstWhere('id', $agent->id)['status']);
    }

    public function test_non_agents_cannot_go_on_duty(): void
    {
        Sanctum::actingAs($this->userWith(['clients.view'])); // not an agent

        $this->postJson('/api/v1/me/duty', ['on' => true])->assertForbidden();
    }

    public function test_positions_are_refused_off_duty_and_stored_on_duty(): void
    {
        $agent = $this->userWith([], isAgent: true);
        Sanctum::actingAs($agent);

        $fix = ['latitude' => self::SITE_LAT, 'longitude' => self::SITE_LNG, 'accuracy_m' => 10];

        $this->postJson('/api/v1/me/positions', $fix)->assertStatus(409);
        $this->assertSame(0, AgentPosition::count());

        $this->postJson('/api/v1/me/duty', ['on' => true]);
        $this->postJson('/api/v1/me/positions', $fix)->assertCreated();
        $this->assertSame(1, AgentPosition::where('user_id', $agent->id)->count());
    }

    public function test_the_geofence_stamps_arrival_then_departure(): void
    {
        $agent = $this->userWith([], isAgent: true);
        $visit = $this->assignedVisit($agent);

        Sanctum::actingAs($agent);
        $this->postJson('/api/v1/me/duty', ['on' => true]);

        // A fix at the site: arrival auto-stamps (and implies acceptance).
        $this->postJson('/api/v1/me/positions', [
            'latitude' => self::SITE_LAT, 'longitude' => self::SITE_LNG,
        ])->assertCreated();

        $visit->refresh();
        $this->assertNotNull($visit->arrived_at);
        $this->assertNotNull($visit->accepted_at);
        $this->assertNull($visit->departed_at);
        $this->assertSame('arrived', $visit->dispatchStatus());

        // ~700 m away — beyond radius × hysteresis: departure stamps.
        $this->postJson('/api/v1/me/positions', [
            'latitude' => self::FAR_LAT, 'longitude' => self::SITE_LNG,
        ])->assertCreated();

        $this->assertNotNull($visit->fresh()->departed_at);
    }

    public function test_the_agent_walks_the_lifecycle_and_strangers_cannot(): void
    {
        $agent = $this->userWith([], isAgent: true);
        $other = $this->userWith([], isAgent: true);
        $visit = $this->assignedVisit($agent);

        Sanctum::actingAs($other);
        $this->postJson("/api/v1/visits/{$visit->id}/accept")->assertForbidden();

        Sanctum::actingAs($agent);
        $this->postJson("/api/v1/visits/{$visit->id}/accept")->assertOk()
            ->assertJsonPath('data.status', 'accepted');
        $this->postJson("/api/v1/visits/{$visit->id}/en-route")->assertOk()
            ->assertJsonPath('data.status', 'en_route');
        $this->postJson("/api/v1/visits/{$visit->id}/arrived")->assertOk()
            ->assertJsonPath('data.status', 'arrived');

        // Replays are harmless no-ops (offline outbox retries).
        $first = $visit->fresh()->accepted_at;
        $this->postJson("/api/v1/visits/{$visit->id}/accept")->assertOk();
        $this->assertTrue($first->equalTo($visit->fresh()->accepted_at));
    }

    public function test_declining_returns_the_plan_to_the_pool_with_the_reason(): void
    {
        Notification::fake();

        $dispatcher = $this->userWith(['visits.dispatch']);
        $agent = $this->userWith([], isAgent: true);
        $visit = $this->assignedVisit($agent);
        $action = $visit->nextAction;

        Sanctum::actingAs($agent);

        $this->postJson("/api/v1/visits/{$visit->id}/decline", [])->assertStatus(422);

        $this->postJson("/api/v1/visits/{$visit->id}/decline", ['reason' => 'Family emergency'])
            ->assertOk();

        $this->assertNull($action->fresh()->assigned_to);
        $visit->refresh();
        $this->assertTrue($visit->isCancelled());
        $this->assertNotNull($visit->declined_at);
        $this->assertSame('Family emergency', $visit->decline_reason);

        Notification::assertSentTo(
            $dispatcher,
            DomainNotification::class,
            fn ($n) => $n->kind === 'visit_declined' && $n->params['reason'] === 'Family emergency',
        );
    }

    public function test_only_the_assigned_agent_may_decline(): void
    {
        $agent = $this->userWith([], isAgent: true);
        $other = $this->userWith([], isAgent: true);
        $visit = $this->assignedVisit($agent);

        Sanctum::actingAs($other);
        $this->postJson("/api/v1/visits/{$visit->id}/decline", ['reason' => 'Nope'])->assertForbidden();
    }

    public function test_reassignment_resets_the_lifecycle_clock(): void
    {
        $agent = $this->userWith([], isAgent: true);
        $next = $this->userWith([], isAgent: true);
        $assigner = $this->userWith(['visits.assign']);
        $visit = $this->assignedVisit($agent);

        Sanctum::actingAs($agent);
        $this->postJson("/api/v1/visits/{$visit->id}/accept")->assertOk();
        $this->assertNotNull($visit->fresh()->accepted_at);

        Sanctum::actingAs($assigner);
        $this->postJson("/api/v1/visits/{$visit->id}/assign", ['agent_id' => $next->id])->assertOk();

        $visit->refresh();
        $this->assertSame($next->id, $visit->agent_id);
        $this->assertNull($visit->accepted_at);
        $this->assertNotNull($visit->assigned_at);
    }

    public function test_suggest_ranks_the_nearer_on_duty_agent_first(): void
    {
        $dispatcher = $this->userWith(['visits.dispatch']);
        $near = $this->userWith([], isAgent: true);
        $far = $this->userWith([], isAgent: true);

        $site = Location::factory()->create([
            'latitude' => self::SITE_LAT, 'longitude' => self::SITE_LNG,
        ]);
        $unit = Unit::factory()->for($site)->create();
        $project = ClientProject::factory()->create(['client_id' => Client::factory()->create()->id]);
        ShortlistItem::factory()->create([
            'client_project_id' => $project->id,
            'shortlistable_type' => 'unit',
            'shortlistable_id' => $unit->id,
            'state' => 'shortlisted',
        ]);
        $action = NextAction::factory()->create([
            'subject_type' => 'client_project', 'subject_id' => $project->id,
            'type' => 'in_site_visit', 'state' => 'pending',
            'assigned_to' => null, 'due_at' => now()->addDay(),
        ]);

        foreach ([$near, $far] as $a) {
            DutySession::create(['user_id' => $a->id, 'started_at' => now()]);
        }
        AgentPosition::create([
            'user_id' => $near->id, 'latitude' => self::SITE_LAT + 0.002,
            'longitude' => self::SITE_LNG, 'recorded_at' => now(),
        ]);
        AgentPosition::create([
            'user_id' => $far->id, 'latitude' => self::SITE_LAT + 0.2, // ~22 km
            'longitude' => self::SITE_LNG, 'recorded_at' => now(),
        ]);

        Sanctum::actingAs($dispatcher);
        $data = $this->getJson("/api/v1/dispatch/suggest?action_id={$action->id}")
            ->assertOk()->json('data');

        $this->assertSame($near->id, $data['candidates'][0]['id']);
        $this->assertNotNull($data['candidates'][0]['distance_km']);
        $this->assertNotNull($data['candidates'][0]['eta_minutes']);
        $this->assertSame($site->id, $data['sites'][0]['id']);

        // Off-duty agents trail the ranking even when they sit closer.
        DutySession::query()->where('user_id', $near->id)->update(['ended_at' => now()]);
        $data = $this->getJson("/api/v1/dispatch/suggest?action_id={$action->id}")
            ->assertOk()->json('data');
        $this->assertSame($far->id, $data['candidates'][0]['id']);
        $this->assertSame('off_duty', collect($data['candidates'])->firstWhere('id', $near->id)['status']);
    }

    public function test_the_live_map_is_dispatcher_only_and_lists_agents_and_sites(): void
    {
        $dispatcher = $this->userWith(['visits.dispatch']);
        $agent = $this->userWith([], isAgent: true);
        $visit = $this->assignedVisit($agent);

        DutySession::create(['user_id' => $agent->id, 'started_at' => now()]);
        AgentPosition::create([
            'user_id' => $agent->id, 'latitude' => self::SITE_LAT,
            'longitude' => self::SITE_LNG, 'recorded_at' => now(),
        ]);

        Sanctum::actingAs($this->userWith(['clients.view']));
        $this->getJson('/api/v1/dispatch/map')->assertForbidden();

        Sanctum::actingAs($dispatcher);
        $data = $this->getJson('/api/v1/dispatch/map')->assertOk()->json('data');

        $row = collect($data['agents'])->firstWhere('id', $agent->id);
        $this->assertSame('available', $row['status']);
        $this->assertEqualsWithDelta(self::SITE_LAT, $row['position']['lat'], 0.0001);
        $this->assertNotEmpty($data['sites']);
        $this->assertSame($visit->id, $data['sites'][0]['visits'][0]['id']);
    }

    public function test_replay_returns_the_days_trail(): void
    {
        $dispatcher = $this->userWith(['visits.dispatch']);
        $agent = $this->userWith([], isAgent: true);

        AgentPosition::create([
            'user_id' => $agent->id, 'latitude' => self::SITE_LAT,
            'longitude' => self::SITE_LNG, 'recorded_at' => now(),
        ]);
        AgentPosition::create([
            'user_id' => $agent->id, 'latitude' => self::FAR_LAT,
            'longitude' => self::SITE_LNG, 'recorded_at' => now()->subDays(2),
        ]);

        Sanctum::actingAs($dispatcher);
        $data = $this->getJson('/api/v1/dispatch/replay?agent_id='.$agent->id.'&date='.now()->toDateString())
            ->assertOk()->json('data');

        $this->assertCount(1, $data['positions']);
        $this->assertEqualsWithDelta(self::SITE_LAT, $data['positions'][0]['lat'], 0.0001);
    }

    public function test_my_day_lists_own_visits_with_site_pins_and_route_order(): void
    {
        $agent = $this->userWith([], isAgent: true);
        $visit = $this->assignedVisit($agent);

        Sanctum::actingAs($agent);
        $data = $this->getJson('/api/v1/me/day')->assertOk()->json('data');

        $this->assertFalse($data['duty']['on']);
        $this->assertCount(1, $data['visits']);
        $this->assertSame($visit->id, $data['visits'][0]['id']);
        $this->assertSame('assigned', $data['visits'][0]['status']);
        $this->assertTrue($data['visits'][0]['can_decline']);
        $this->assertEqualsWithDelta(self::SITE_LAT, $data['visits'][0]['site']['lat'], 0.0001);
        $this->assertSame([$visit->id], $data['route_order']);
    }

    public function test_the_sweeper_nudges_dispatchers_once_for_unaccepted_and_late_visits(): void
    {
        Notification::fake();
        Carbon::setTestNow(now()->setTime(14, 0));

        $dispatcher = $this->userWith(['visits.dispatch']);
        $agent = $this->userWith([], isAgent: true);

        // Assigned 30 min ago, never accepted (default SLA 15).
        $unaccepted = $this->assignedVisit($agent);
        $unaccepted->newQuery()->whereKey($unaccepted->id)
            ->update(['assigned_at' => now()->subMinutes(30)]);

        // Timed for 13:00 today, never arrived (default grace 15).
        $late = $this->assignedVisit($agent);
        $late->update(['scheduled_at' => now()->subHour(), 'accepted_at' => now()->subHours(2)]);

        $this->artisan('dispatch:sweep')->assertSuccessful();

        Notification::assertSentTo($dispatcher, DomainNotification::class, fn ($n) => $n->kind === 'visit_unaccepted');
        Notification::assertSentTo($dispatcher, DomainNotification::class, fn ($n) => $n->kind === 'visit_late');

        // One-shot: a second sweep stays silent.
        Notification::fake();
        $this->artisan('dispatch:sweep')->assertSuccessful();
        Notification::assertNothingSent();

        Carbon::setTestNow();
    }

    public function test_locate_pings_only_on_duty_agents_and_throttles(): void
    {
        \Illuminate\Support\Facades\Cache::flush();
        $dispatcher = $this->userWith(['visits.dispatch']);
        $agent = $this->userWith([], isAgent: true);

        Sanctum::actingAs($dispatcher);

        // Off duty: nothing to ping.
        $this->postJson('/api/v1/dispatch/locate', ['agent_id' => $agent->id])
            ->assertOk()->assertJsonPath('data.pinged', 0);

        DutySession::create(['user_id' => $agent->id, 'started_at' => now()]);
        $this->postJson('/api/v1/dispatch/locate', ['agent_id' => $agent->id])
            ->assertOk()->assertJsonPath('data.pinged', 1);

        // Throttled: five dispatchers staring cost one burst a minute, not five.
        $this->postJson('/api/v1/dispatch/locate', ['agent_id' => $agent->id])
            ->assertOk()->assertJsonPath('data.pinged', 0);
    }

    public function test_the_position_response_carries_the_precision_cue(): void
    {
        $agent = $this->userWith([], isAgent: true);
        $visit = $this->assignedVisit($agent);

        Sanctum::actingAs($agent);
        $this->postJson('/api/v1/me/duty', ['on' => true]);

        // Idle (nothing en route): coast on coarse fixes.
        $this->postJson('/api/v1/me/positions', [
            'latitude' => self::SITE_LAT + 0.05, 'longitude' => self::SITE_LNG,
        ])->assertCreated()->assertJsonPath('data.precision', false);

        // A live leg (en route, still ~5.5 km out): keep real GPS on.
        $this->postJson("/api/v1/visits/{$visit->id}/en-route")->assertOk();
        $this->postJson('/api/v1/me/positions', [
            'latitude' => self::SITE_LAT + 0.05, 'longitude' => self::SITE_LNG,
        ])->assertCreated()->assertJsonPath('data.precision', true);
    }

    public function test_the_sweeper_closes_forgotten_duty_sessions(): void
    {
        $agent = $this->userWith([], isAgent: true);
        DutySession::create(['user_id' => $agent->id, 'started_at' => now()->subHours(26)]);

        $this->artisan('dispatch:sweep')->assertSuccessful();

        $this->assertNotNull(DutySession::first()->ended_at);
    }
}
