<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Models\Location;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The project-level "unavailable" veil (is_available): the promoteur parks a
 * whole project off the market. It drops out of selectors (?selectable) but
 * stays in the management list (tagged), and reactivates cleanly.
 */
class LocationAvailabilityTest extends TestCase
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

    private function manager(): User
    {
        return $this->userWithPermissions(['units.view', 'locations.manage']);
    }

    public function test_make_unavailable_parks_the_project(): void
    {
        $location = Location::factory()->create();
        Sanctum::actingAs($this->manager());

        $this->postJson("/api/v1/locations/{$location->id}/unavailable")
            ->assertOk()
            ->assertJsonPath('data.is_available', false);

        $this->assertDatabaseHas('locations', ['id' => $location->id, 'is_available' => false]);
    }

    public function test_make_available_brings_the_project_back(): void
    {
        $location = Location::factory()->create(['is_available' => false]);
        Sanctum::actingAs($this->manager());

        $this->postJson("/api/v1/locations/{$location->id}/available")
            ->assertOk()
            ->assertJsonPath('data.is_available', true);

        $this->assertDatabaseHas('locations', ['id' => $location->id, 'is_available' => true]);
    }

    public function test_toggles_require_locations_manage(): void
    {
        $location = Location::factory()->create();
        Sanctum::actingAs($this->userWithPermissions(['units.view']));

        $this->postJson("/api/v1/locations/{$location->id}/unavailable")->assertForbidden();
        $this->assertDatabaseHas('locations', ['id' => $location->id, 'is_available' => true]);
    }

    public function test_selectable_index_hides_parked_projects_but_management_keeps_them(): void
    {
        $live = Location::factory()->create(['name' => 'Live Project']);
        $parked = Location::factory()->create(['name' => 'Parked Project', 'is_available' => false]);

        Sanctum::actingAs($this->manager());

        // Selector mode: parked project gone.
        $selectable = collect($this->getJson('/api/v1/locations?selectable=1')->assertOk()->json('data'))
            ->pluck('id');
        $this->assertTrue($selectable->contains($live->id));
        $this->assertFalse($selectable->contains($parked->id));

        // Default management list: parked project still present (tagged).
        $all = collect($this->getJson('/api/v1/locations')->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($all->contains($parked->id));
    }

    public function test_non_managers_never_see_parked_projects_in_the_default_list(): void
    {
        $live = Location::factory()->create();
        $parked = Location::factory()->create(['is_available' => false]);

        // units.view only — neither locations.manage nor units.manage, so even
        // the default (non-selector) list veils the parked project.
        Sanctum::actingAs($this->userWithPermissions(['units.view']));

        $ids = collect($this->getJson('/api/v1/locations')->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($live->id));
        $this->assertFalse($ids->contains($parked->id));
    }

    public function test_a_units_manager_without_locations_manage_still_sees_parked_stock(): void
    {
        $parked = Location::factory()->create(['is_available' => false]);

        Sanctum::actingAs($this->userWithPermissions(['units.view', 'units.manage']));

        $ids = collect($this->getJson('/api/v1/locations')->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($parked->id));
    }
}
