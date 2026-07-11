<?php

declare(strict_types=1);

namespace Tests\Feature\Pipeline;

use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Clients\Models\ShortlistItem;
use App\Modules\Collaboration\Notifications\DomainNotification;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Pipeline\Models\AgentMileageDay;
use App\Modules\Pipeline\Models\AgentPosition;
use App\Modules\Pipeline\Models\DutySession;
use App\Modules\Pipeline\Models\NextAction;
use App\Modules\Pipeline\Models\Visit;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The path-tracking half of the dispatch GPS layer: duty snapshots pacing,
 * road-true distances via OSRM (with the haversine fallback), the nearest-
 * agents panel, the day-plan optimizer, the hour-windowed replay, daily
 * mileage, and the idle / off-route dispatcher alerts.
 *
 * phpunit.xml pins OSRM_URL to a dead port, so the engine is "down" unless a
 * test fakes it — routed behaviour is always exercised through Http::fake.
 */
class DispatchPathTrackingTest extends TestCase
{
    use RefreshDatabase;

    private const SITE_LAT = 36.7538;

    private const SITE_LNG = 3.0588;

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

    /** A pending, unassigned in-site plan pointing at the given site. */
    private function pendingPlan(Location $site): NextAction
    {
        $unit = Unit::factory()->for($site)->create();
        $project = ClientProject::factory()->create(['client_id' => Client::factory()->create()->id]);
        ShortlistItem::factory()->create([
            'client_project_id' => $project->id,
            'shortlistable_type' => 'unit',
            'shortlistable_id' => $unit->id,
            'state' => 'shortlisted',
        ]);

        return NextAction::factory()->create([
            'subject_type' => 'client_project', 'subject_id' => $project->id,
            'type' => 'in_site_visit', 'state' => 'pending',
            'assigned_to' => null, 'due_at' => now()->addDay(),
        ]);
    }

    private function onDutyAt(User $agent, float $lat, float $lng): void
    {
        DutySession::create(['user_id' => $agent->id, 'started_at' => now()]);
        AgentPosition::create([
            'user_id' => $agent->id, 'latitude' => $lat, 'longitude' => $lng,
            'recorded_at' => now(),
        ]);
    }

    public function test_the_duty_and_position_responses_carry_the_snapshot_pace(): void
    {
        $agent = $this->userWith([], isAgent: true);
        Sanctum::actingAs($agent);

        // Default cadence: 5 minutes.
        $this->postJson('/api/v1/me/duty', ['on' => true])
            ->assertOk()->assertJsonPath('data.snapshot_s', 300);
        $this->postJson('/api/v1/me/positions', [
            'latitude' => self::SITE_LAT, 'longitude' => self::SITE_LNG,
        ])->assertCreated()->assertJsonPath('data.snapshot_s', 300);

        // The setting re-paces every device without an APK (0 = paths off).
        \App\Modules\Settings\Models\AppSetting::set('dispatch_snapshot_minutes', '0');
        $this->postJson('/api/v1/me/positions', [
            'latitude' => self::SITE_LAT, 'longitude' => self::SITE_LNG,
        ])->assertCreated()->assertJsonPath('data.snapshot_s', 0);
    }

    public function test_suggest_uses_road_distances_when_the_engine_answers(): void
    {
        Http::fake(['*/table/v1/*' => Http::response([
            'code' => 'Ok',
            'durations' => [[600.0]],
            'distances' => [[5200.0]],
        ])]);

        $dispatcher = $this->userWith(['visits.dispatch']);
        $agent = $this->userWith([], isAgent: true);
        $site = Location::factory()->create(['latitude' => self::SITE_LAT, 'longitude' => self::SITE_LNG]);
        $action = $this->pendingPlan($site);
        $this->onDutyAt($agent, self::SITE_LAT + 0.05, self::SITE_LNG);

        Sanctum::actingAs($dispatcher);
        $data = $this->getJson("/api/v1/dispatch/suggest?action_id={$action->id}")
            ->assertOk()->json('data');

        $row = collect($data['candidates'])->firstWhere('id', $agent->id);
        $this->assertTrue($row['routed']);
        $this->assertSame(5.2, $row['distance_km']);
        // 600 s = 10 min free-flow × 1.3 congestion = 13.
        $this->assertSame(13, $row['eta_minutes']);
    }

    public function test_suggest_falls_back_to_estimates_when_the_engine_is_down(): void
    {
        $dispatcher = $this->userWith(['visits.dispatch']);
        $agent = $this->userWith([], isAgent: true);
        $site = Location::factory()->create(['latitude' => self::SITE_LAT, 'longitude' => self::SITE_LNG]);
        $action = $this->pendingPlan($site);
        $this->onDutyAt($agent, self::SITE_LAT + 0.05, self::SITE_LNG); // ~5.5 km

        Sanctum::actingAs($dispatcher);
        $data = $this->getJson("/api/v1/dispatch/suggest?action_id={$action->id}")
            ->assertOk()->json('data');

        $row = collect($data['candidates'])->firstWhere('id', $agent->id);
        $this->assertFalse($row['routed']);
        $this->assertEqualsWithDelta(5.5, $row['distance_km'], 0.3);
        $this->assertNotNull($row['eta_minutes']);
    }

    public function test_nearest_ranks_on_duty_agents_by_drive_time(): void
    {
        Cache::flush();
        Http::fake(['*/table/v1/*' => Http::response([
            'code' => 'Ok',
            'durations' => [[900.0], [300.0]],
            'distances' => [[9000.0], [2500.0]],
        ])]);

        $dispatcher = $this->userWith(['visits.dispatch']);
        $far = $this->userWith([], isAgent: true);
        $near = $this->userWith([], isAgent: true);
        $offDuty = $this->userWith([], isAgent: true);
        // The controller lists agents by NAME — pin the names so the faked
        // matrix rows (far = 900 s, near = 300 s) line up deterministically.
        $far->update(['name' => 'Aaa Far']);
        $near->update(['name' => 'Bbb Near']);
        $site = Location::factory()->create(['latitude' => self::SITE_LAT, 'longitude' => self::SITE_LNG]);

        $this->onDutyAt($far, self::SITE_LAT + 0.1, self::SITE_LNG);
        $this->onDutyAt($near, self::SITE_LAT + 0.02, self::SITE_LNG);

        Sanctum::actingAs($this->userWith(['clients.view']));
        $this->getJson("/api/v1/dispatch/nearest?location_id={$site->id}")->assertForbidden();

        Sanctum::actingAs($dispatcher);
        $data = $this->getJson("/api/v1/dispatch/nearest?location_id={$site->id}")
            ->assertOk()->json('data');

        $this->assertSame($site->id, $data['site']['id']);
        $ids = collect($data['agents'])->pluck('id');
        $this->assertSame($near->id, $ids->first());
        $this->assertNotContains($offDuty->id, $ids->all());
        $this->assertSame(7, $data['agents'][0]['eta_minutes']); // 300 s × 1.3 = 6.5 → 7
        $this->assertSame(2.5, $data['agents'][0]['distance_km']);

        // A site with no pin cannot rank anyone.
        $bare = Location::factory()->create(['latitude' => null, 'longitude' => null]);
        $this->getJson("/api/v1/dispatch/nearest?location_id={$bare->id}")->assertStatus(422);
    }

    public function test_plan_preview_splits_the_pool_and_flags_unpinned_plans(): void
    {
        $dispatcher = $this->userWith(['visits.dispatch']);
        $a = $this->userWith([], isAgent: true);
        $b = $this->userWith([], isAgent: true);

        $siteNorth = Location::factory()->create(['latitude' => self::SITE_LAT + 0.05, 'longitude' => self::SITE_LNG]);
        $siteSouth = Location::factory()->create(['latitude' => self::SITE_LAT - 0.05, 'longitude' => self::SITE_LNG]);
        $unpinned = Location::factory()->create(['latitude' => null, 'longitude' => null]);

        $north = $this->pendingPlan($siteNorth);
        $south = $this->pendingPlan($siteSouth);
        $lost = $this->pendingPlan($unpinned);

        $this->onDutyAt($a, self::SITE_LAT + 0.06, self::SITE_LNG); // beside north
        $this->onDutyAt($b, self::SITE_LAT - 0.06, self::SITE_LNG); // beside south

        Sanctum::actingAs($dispatcher);
        $data = $this->getJson('/api/v1/dispatch/plan-preview')->assertOk()->json('data');

        $this->assertSame(3, $data['pool_size']);
        $this->assertFalse($data['routed']); // engine down → estimates

        $stops = collect($data['proposals'])
            ->flatMap(fn ($p) => collect($p['stops'])->map(fn ($s) => [$s['action_id'], $p['agent']['id']]))
            ->mapWithKeys(fn ($pair) => [$pair[0] => $pair[1]]);
        // Proximity + balance: each agent takes the plan at their doorstep.
        $this->assertSame($a->id, $stops[$north->id]);
        $this->assertSame($b->id, $stops[$south->id]);

        $this->assertSame($lost->id, $data['skipped'][0]['action_id']);
        $this->assertSame('no_pin', $data['skipped'][0]['reason']);
    }

    public function test_replay_filters_by_hour_window_and_reports_distance(): void
    {
        Carbon::setTestNow(now()->setTime(18, 0));
        $dispatcher = $this->userWith(['visits.dispatch']);
        $agent = $this->userWith([], isAgent: true);
        DutySession::create(['user_id' => $agent->id, 'started_at' => now()->setTime(8, 30)]);

        foreach ([[9, self::SITE_LAT], [10, self::SITE_LAT + 0.02], [15, self::SITE_LAT + 0.04]] as [$hour, $lat]) {
            AgentPosition::create([
                'user_id' => $agent->id, 'latitude' => $lat, 'longitude' => self::SITE_LNG,
                'recorded_at' => now()->setTime($hour, 0),
            ]);
        }

        Sanctum::actingAs($dispatcher);
        $data = $this->getJson('/api/v1/dispatch/replay?agent_id='.$agent->id
            .'&date='.now()->toDateString().'&from=08:00&until=12:00')
            ->assertOk()->json('data');

        $this->assertCount(2, $data['positions']); // the 15:00 fix is outside
        $this->assertGreaterThan(2.0, $data['distance_km']); // ~2.2 km road-corrected
        $this->assertCount(1, $data['sessions']);

        Carbon::setTestNow();
    }

    public function test_mileage_aggregates_history_and_reports_live_today(): void
    {
        Carbon::setTestNow(now()->setTime(14, 0));
        $dispatcher = $this->userWith(['visits.dispatch']);
        $agent = $this->userWith([], isAgent: true);

        // Yesterday: two fixes ~2.2 km apart during a closed duty stretch.
        $yesterday = now()->subDay();
        DutySession::create([
            'user_id' => $agent->id,
            'started_at' => $yesterday->copy()->setTime(9, 0),
            'ended_at' => $yesterday->copy()->setTime(17, 0),
        ]);
        AgentPosition::create([
            'user_id' => $agent->id, 'latitude' => self::SITE_LAT, 'longitude' => self::SITE_LNG,
            'recorded_at' => $yesterday->copy()->setTime(10, 0),
        ]);
        AgentPosition::create([
            'user_id' => $agent->id, 'latitude' => self::SITE_LAT + 0.02, 'longitude' => self::SITE_LNG,
            'recorded_at' => $yesterday->copy()->setTime(10, 30),
        ]);

        $this->artisan('dispatch:mileage', ['--day' => $yesterday->toDateString()])->assertSuccessful();

        $row = AgentMileageDay::query()->where('user_id', $agent->id)->first();
        $this->assertNotNull($row);
        $this->assertGreaterThan(2.0, $row->km);
        $this->assertSame(480, $row->duty_minutes);

        // Today: on duty right now with a couple of fresh fixes — reported live.
        $this->onDutyAt($agent, self::SITE_LAT, self::SITE_LNG);

        Sanctum::actingAs($dispatcher);
        $data = $this->getJson('/api/v1/dispatch/mileage?from='.$yesterday->toDateString()
            .'&until='.now()->toDateString())->assertOk()->json('data');

        $mine = collect($data['agents'])->firstWhere('id', $agent->id);
        $this->assertCount(2, $mine['days']); // yesterday aggregated + today live
        $this->assertGreaterThan(2.0, $mine['total_km']);

        Carbon::setTestNow();
    }

    public function test_the_sweeper_flags_a_parked_available_agent_once(): void
    {
        Notification::fake();
        Cache::flush();
        Carbon::setTestNow(now()->setTime(14, 0));

        $dispatcher = $this->userWith(['visits.dispatch']);
        $parked = $this->userWith([], isAgent: true);
        DutySession::create(['user_id' => $parked->id, 'started_at' => now()->subHours(3)]);

        // Four fixes on the same spot spanning 50 minutes (threshold 45).
        foreach ([50, 35, 20, 5] as $minutesAgo) {
            AgentPosition::create([
                'user_id' => $parked->id, 'latitude' => self::SITE_LAT,
                'longitude' => self::SITE_LNG, 'recorded_at' => now()->subMinutes($minutesAgo),
            ]);
        }

        $this->artisan('dispatch:sweep')->assertSuccessful();
        Notification::assertSentTo(
            $dispatcher,
            DomainNotification::class,
            fn ($n) => $n->kind === 'agent_idle' && (int) $n->params['minutes'] >= 45,
        );

        // One alert per stop, however long it lasts.
        Notification::fake();
        $this->artisan('dispatch:sweep')->assertSuccessful();
        Notification::assertNothingSent();

        Carbon::setTestNow();
    }

    public function test_accepting_an_imminent_visit_stamps_en_route_too(): void
    {
        Carbon::setTestNow(now()->setTime(10, 0));
        $agent = $this->userWith([], isAgent: true);

        $site = Location::factory()->create(['latitude' => self::SITE_LAT, 'longitude' => self::SITE_LNG]);
        $unit = Unit::factory()->for($site)->create();
        $project = ClientProject::factory()->create(['client_id' => Client::factory()->create()->id]);

        $later = Visit::factory()->inSite()->create([
            'client_id' => $project->client_id, 'client_project_id' => $project->id,
            'unit_id' => $unit->id, 'agent_id' => $agent->id,
            'scheduled_at' => now()->setTime(20, 0), 'completed_at' => null,
        ]);
        $soon = Visit::factory()->inSite()->create([
            'client_id' => $project->client_id, 'client_project_id' => $project->id,
            'unit_id' => $unit->id, 'agent_id' => $agent->id,
            'scheduled_at' => now()->addMinutes(30), 'completed_at' => null,
        ]);

        Sanctum::actingAs($agent);

        // A tonight slot accepted in the morning: accepted only — precision
        // GPS must not burn the battery all day.
        $this->postJson("/api/v1/visits/{$later->id}/accept")->assertOk()
            ->assertJsonPath('data.status', 'accepted');

        // Due in 30 minutes: accepting means "I'm going now".
        $this->postJson("/api/v1/visits/{$soon->id}/accept")->assertOk()
            ->assertJsonPath('data.status', 'en_route');

        Carbon::setTestNow();
    }

    public function test_motion_toward_an_accepted_site_stamps_en_route_without_a_tap(): void
    {
        Carbon::setTestNow(now()->setTime(10, 0));
        $agent = $this->userWith([], isAgent: true);

        $site = Location::factory()->create(['latitude' => self::SITE_LAT, 'longitude' => self::SITE_LNG]);
        $unit = Unit::factory()->for($site)->create();
        $project = ClientProject::factory()->create(['client_id' => Client::factory()->create()->id]);
        $visit = Visit::factory()->inSite()->create([
            'client_id' => $project->client_id, 'client_project_id' => $project->id,
            'unit_id' => $unit->id, 'agent_id' => $agent->id,
            'scheduled_at' => now()->setTime(20, 0), 'completed_at' => null,
            'accepted_at' => now(),
        ]);

        Sanctum::actingAs($agent);
        $this->postJson('/api/v1/me/duty', ['on' => true]);

        // Parked ~6.6 km out: one fix alone proves nothing.
        $this->postJson('/api/v1/me/positions', [
            'latitude' => self::SITE_LAT + 0.06, 'longitude' => self::SITE_LNG,
        ])->assertCreated();
        $this->assertNull($visit->fresh()->en_route_at);

        // Next snapshot ~1.1 km closer: the drive started — en route, and the
        // server asks the device for precision GPS on the same breath.
        $this->postJson('/api/v1/me/positions', [
            'latitude' => self::SITE_LAT + 0.05, 'longitude' => self::SITE_LNG,
        ])->assertCreated()->assertJsonPath('data.precision', true);
        $this->assertNotNull($visit->fresh()->en_route_at);

        Carbon::setTestNow();
    }

    public function test_the_live_map_draws_en_route_legs(): void
    {
        $dispatcher = $this->userWith(['visits.dispatch']);
        $agent = $this->userWith([], isAgent: true);

        $site = Location::factory()->create(['latitude' => self::SITE_LAT, 'longitude' => self::SITE_LNG]);
        $unit = Unit::factory()->for($site)->create();
        $project = ClientProject::factory()->create(['client_id' => Client::factory()->create()->id]);
        $visit = Visit::factory()->inSite()->create([
            'client_id' => $project->client_id, 'client_project_id' => $project->id,
            'unit_id' => $unit->id, 'agent_id' => $agent->id,
            'scheduled_at' => now()->startOfDay()->addHours(20), 'completed_at' => null,
            'accepted_at' => now(), 'en_route_at' => now(),
        ]);
        $this->onDutyAt($agent, self::SITE_LAT + 0.05, self::SITE_LNG);

        Sanctum::actingAs($dispatcher);
        $data = $this->getJson('/api/v1/dispatch/map')->assertOk()->json('data');

        $leg = collect($data['legs'])->firstWhere('visit_id', $visit->id);
        $this->assertNotNull($leg);
        $this->assertSame($agent->id, $leg['agent_id']);
        $this->assertSame($site->id, $leg['site']['id']);
        $this->assertNotNull($leg['eta_minutes']);
        // Engine down → the leg degrades to a straight two-point line, flagged.
        $this->assertFalse($leg['routed']);
        $this->assertCount(2, $leg['polyline']);
    }

    public function test_departure_prompts_the_agent_to_log_the_visit(): void
    {
        Notification::fake();
        $agent = $this->userWith([], isAgent: true);

        $site = Location::factory()->create(['latitude' => self::SITE_LAT, 'longitude' => self::SITE_LNG]);
        $unit = Unit::factory()->for($site)->create();
        $project = ClientProject::factory()->create(['client_id' => Client::factory()->create()->id]);
        $visit = Visit::factory()->inSite()->create([
            'client_id' => $project->client_id, 'client_project_id' => $project->id,
            'unit_id' => $unit->id, 'agent_id' => $agent->id,
            'scheduled_at' => now()->startOfDay()->addHours(20), 'completed_at' => null,
            'accepted_at' => now(), 'en_route_at' => now(), 'arrived_at' => now(),
        ]);

        Sanctum::actingAs($agent);
        $this->postJson('/api/v1/me/duty', ['on' => true]);

        // ~700 m away — beyond radius × hysteresis: departure + the nudge.
        $this->postJson('/api/v1/me/positions', [
            'latitude' => self::SITE_LAT + 0.0063, 'longitude' => self::SITE_LNG,
        ])->assertCreated();

        $this->assertNotNull($visit->fresh()->departed_at);
        Notification::assertSentTo(
            $agent,
            DomainNotification::class,
            fn ($n) => $n->kind === 'visit_log_prompt' && $n->subjectId === $visit->id,
        );
    }

    public function test_offroute_drift_flags_the_dispatchers_once(): void
    {
        Notification::fake();
        Cache::flush();

        // The corridor OSRM proposes: straight down the meridian to the site.
        Http::fake(['*/route/v1/*' => Http::response([
            'code' => 'Ok',
            'routes' => [[
                'distance' => 5500.0,
                'duration' => 700.0,
                'geometry' => ['coordinates' => [
                    [self::SITE_LNG, self::SITE_LAT - 0.05],
                    [self::SITE_LNG, self::SITE_LAT],
                ]],
            ]],
        ])]);

        $dispatcher = $this->userWith(['visits.dispatch']);
        $agent = $this->userWith([], isAgent: true);

        $site = Location::factory()->create(['latitude' => self::SITE_LAT, 'longitude' => self::SITE_LNG]);
        $unit = Unit::factory()->for($site)->create();
        $project = ClientProject::factory()->create(['client_id' => Client::factory()->create()->id]);
        $visit = Visit::factory()->inSite()->create([
            'client_id' => $project->client_id, 'client_project_id' => $project->id,
            'unit_id' => $unit->id, 'agent_id' => $agent->id,
            'scheduled_at' => now()->startOfDay()->addHours(20), 'completed_at' => null,
        ]);

        Sanctum::actingAs($agent);
        $this->postJson('/api/v1/me/duty', ['on' => true]);
        $this->postJson("/api/v1/visits/{$visit->id}/en-route")->assertOk();

        // On the road: this first fix builds the corridor.
        $this->postJson('/api/v1/me/positions', [
            'latitude' => self::SITE_LAT - 0.05, 'longitude' => self::SITE_LNG,
        ])->assertCreated();

        // ~2.7 km east of the corridor, twice: strike one, then the alert.
        $this->postJson('/api/v1/me/positions', [
            'latitude' => self::SITE_LAT - 0.04, 'longitude' => self::SITE_LNG + 0.03,
        ])->assertCreated();
        $this->assertNull($visit->fresh()->offroute_alerted_at);

        $this->postJson('/api/v1/me/positions', [
            'latitude' => self::SITE_LAT - 0.03, 'longitude' => self::SITE_LNG + 0.03,
        ])->assertCreated();
        $this->assertNotNull($visit->fresh()->offroute_alerted_at);
        Notification::assertSentTo($dispatcher, DomainNotification::class, fn ($n) => $n->kind === 'visit_offroute');

        // One-shot: staying lost does not nag again.
        Notification::fake();
        $this->postJson('/api/v1/me/positions', [
            'latitude' => self::SITE_LAT - 0.02, 'longitude' => self::SITE_LNG + 0.03,
        ])->assertCreated();
        Notification::assertNothingSent();
    }
}
