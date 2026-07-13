<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The units.stats gate on the commercial-intelligence reads: the project
 * performance tab (/locations/{id}/insights, route-gated) and the unit page's
 * pipeline stats (field-gated inside /units/{id}/insights so the payments
 * block keeps riding units.view + versements.view).
 */
class InsightsPermissionTest extends TestCase
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

    public function test_location_insights_require_units_stats(): void
    {
        $location = Location::factory()->create();

        Sanctum::actingAs($this->userWithPermissions(['units.view']));

        $this->getJson("/api/v1/locations/{$location->id}/insights")->assertForbidden();
    }

    public function test_location_insights_open_with_units_stats(): void
    {
        $location = Location::factory()->create();
        Unit::factory()->create(['location_id' => $location->id]);

        Sanctum::actingAs($this->userWithPermissions(['units.view', 'units.stats']));

        $this->getJson("/api/v1/locations/{$location->id}/insights")
            ->assertOk()
            ->assertJsonPath('data.units.total', 1);
    }

    public function test_unit_insights_omit_stats_without_units_stats(): void
    {
        $unit = Unit::factory()->create();

        Sanctum::actingAs($this->userWithPermissions(['units.view']));

        $data = $this->getJson("/api/v1/units/{$unit->id}/insights")
            ->assertOk()->json('data');

        $this->assertArrayNotHasKey('stats', $data);
    }

    public function test_unit_insights_include_stats_with_units_stats(): void
    {
        $unit = Unit::factory()->create();

        Sanctum::actingAs($this->userWithPermissions(['units.view', 'units.stats']));

        $this->getJson("/api/v1/units/{$unit->id}/insights")
            ->assertOk()
            ->assertJsonPath('data.stats.sale_status', $unit->sale_status?->value);
    }

    public function test_payments_block_still_rides_versements_view_without_stats(): void
    {
        // The payment desk keeps its unit-page payments tab even when the
        // stats grant is not ticked for its role.
        $unit = Unit::factory()->create();

        Sanctum::actingAs($this->userWithPermissions(['units.view', 'versements.view']));

        $data = $this->getJson("/api/v1/units/{$unit->id}/insights")
            ->assertOk()->json('data');

        $this->assertArrayNotHasKey('stats', $data);
        $this->assertArrayHasKey('payments', $data);
    }
}
