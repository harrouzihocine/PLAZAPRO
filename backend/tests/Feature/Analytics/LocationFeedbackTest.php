<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Modules\Clients\Enums\ShortlistState;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Clients\Models\ShortlistItem;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Pipeline\Models\Call;
use App\Modules\Pipeline\Models\Visit;
use App\Modules\Settings\Models\DynamicList;
use App\Modules\Settings\Models\DynamicListItem;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LocationFeedbackTest extends TestCase
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

    private function item(string $listKey, string $value): int
    {
        $list = DynamicList::firstOrCreate(['key' => $listKey], ['name' => Str::headline($listKey), 'is_system' => true]);

        return DynamicListItem::firstOrCreate(
            ['dynamic_list_id' => $list->id, 'value' => $value],
            ['label' => Str::headline($value)],
        )->id;
    }

    public function test_it_aggregates_objections_and_funnel_scoped_to_the_location(): void
    {
        $location = Location::factory()->create();
        $unit = Unit::factory()->create(['location_id' => $location->id]);
        $project = ClientProject::factory()->create(['location_id' => $location->id]);

        $price = $this->item('objection_reasons', 'price_too_high');
        $plan = $this->item('objection_reasons', 'payment_plan_too_short');

        // Two calls + one in-site visit on THIS development carry objections.
        Call::factory()->create(['client_project_id' => $project->id, 'objections' => [$price, $plan], 'called_at' => now()]);
        Call::factory()->create(['client_project_id' => $project->id, 'objections' => [$price], 'called_at' => now()]);
        Visit::factory()->inSite()->completed()->create([
            'client_project_id' => $project->id, 'unit_id' => $unit->id, 'objections' => [$price],
        ]);

        // A liked property (interest signal).
        ShortlistItem::factory()->create([
            'client_project_id' => $project->id,
            'shortlistable_id' => $unit->id,
            'state' => ShortlistState::VisitedInterested->value,
        ]);

        // Another development's objection must NOT leak in.
        $other = Location::factory()->create();
        $otherProject = ClientProject::factory()->create(['location_id' => $other->id]);
        Call::factory()->create(['client_project_id' => $otherProject->id, 'objections' => [$price], 'called_at' => now()]);

        Sanctum::actingAs($this->user(['dashboard.view', 'reports.view']));

        $data = $this->getJson("/api/v1/analytics/locations/{$location->id}/feedback")
            ->assertOk()->json('data');

        // Price appears 3× here (2 calls + 1 visit), plan 1× — the other location excluded.
        $this->assertSame('location', $data['scope']);
        $this->assertSame($price, $data['objections'][0]['id']);
        $this->assertSame(3, $data['objections'][0]['count']);
        $this->assertSame(1, collect($data['objections'])->firstWhere('id', $plan)['count']);

        $this->assertSame(2, $data['funnel']['calls']);
        $this->assertSame(1, $data['funnel']['in_site_visits']);
        $this->assertSame(1, $data['funnel']['shortlisted']);
        $this->assertSame(1, $data['funnel']['interested']);
        $this->assertSame(1, $data['sentiment']['positive']);

        // The most-demanded unit surfaces on the leaderboard.
        $this->assertSame($unit->id, $data['demand_units'][0]['id']);
    }

    public function test_it_ranks_why_deals_were_lost(): void
    {
        $location = Location::factory()->create();
        $reason = $this->item('cancellation_reasons', 'price_too_high');

        ClientProject::factory()->count(2)->create([
            'location_id' => $location->id,
            'archive_reason_id' => $reason,
        ]);
        // A live project with no lost reason must not count.
        ClientProject::factory()->create(['location_id' => $location->id]);

        Sanctum::actingAs($this->user(['dashboard.view', 'reports.view']));

        $data = $this->getJson("/api/v1/analytics/locations/{$location->id}/feedback")
            ->assertOk()->json('data');

        $this->assertSame($reason, $data['lost_reasons'][0]['id']);
        $this->assertSame(2, $data['lost_reasons'][0]['count']);
    }

    public function test_unit_drilldown_uses_visits_only_and_excludes_calls(): void
    {
        $location = Location::factory()->create();
        $unit = Unit::factory()->create(['location_id' => $location->id]);
        $project = ClientProject::factory()->create(['location_id' => $location->id]);

        $price = $this->item('objection_reasons', 'price_too_high');

        // A call objection (rolls up to the development, NOT the unit).
        Call::factory()->create(['client_project_id' => $project->id, 'objections' => [$price], 'called_at' => now()]);
        // An in-site visit objection on the unit (attributes to the unit).
        Visit::factory()->inSite()->completed()->create([
            'client_project_id' => $project->id, 'unit_id' => $unit->id, 'objections' => [$price],
        ]);

        Sanctum::actingAs($this->user(['dashboard.view', 'reports.view']));

        $data = $this->getJson("/api/v1/analytics/units/{$unit->id}/feedback")
            ->assertOk()->json('data');

        $this->assertSame('unit', $data['scope']);
        // Calls don't attribute to a unit, so only the visit objection counts.
        $this->assertSame(0, $data['funnel']['calls']);
        $this->assertSame(1, $data['funnel']['in_site_visits']);
        $this->assertSame(1, $data['objections'][0]['count']);
        $this->assertSame([], $data['demand_units']);
    }

    public function test_a_date_window_scopes_the_aggregation(): void
    {
        $location = Location::factory()->create();
        $project = ClientProject::factory()->create(['location_id' => $location->id]);
        $price = $this->item('objection_reasons', 'price_too_high');

        Call::factory()->create(['client_project_id' => $project->id, 'objections' => [$price], 'called_at' => now()->subDays(3)]);
        Call::factory()->create(['client_project_id' => $project->id, 'objections' => [$price], 'called_at' => now()->subDays(120)]);

        Sanctum::actingAs($this->user(['dashboard.view', 'reports.view']));

        $data = $this->getJson("/api/v1/analytics/locations/{$location->id}/feedback?from=".now()->subDays(30)->toDateString().'&to='.now()->toDateString())
            ->assertOk()->json('data');

        // Only the recent call falls inside the 30-day window.
        $this->assertSame(1, $data['funnel']['calls']);
    }

    public function test_feedback_requires_the_reports_permission(): void
    {
        $location = Location::factory()->create();

        Sanctum::actingAs($this->user(['dashboard.view']));

        $this->getJson("/api/v1/analytics/locations/{$location->id}/feedback")->assertForbidden();
    }
}
