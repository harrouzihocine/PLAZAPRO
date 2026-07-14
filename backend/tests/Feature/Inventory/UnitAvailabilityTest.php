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
 * The "unavailable" (parked off the market) state for a unit: the promoteur
 * withholds it from selling. It vanishes from every selector (?selectable) and
 * from desire matching, but stays visible in management and reactivates cleanly.
 */
class UnitAvailabilityTest extends TestCase
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
        return $this->userWithPermissions(['units.view', 'units.manage']);
    }

    // ── Single-unit park / un-park ──────────────────────────────────────

    public function test_make_unavailable_parks_an_available_unit(): void
    {
        $unit = Unit::factory()->create();
        Sanctum::actingAs($this->manager());

        $this->postJson("/api/v1/units/{$unit->id}/unavailable")
            ->assertOk()
            ->assertJsonPath('data.sale_status', 'unavailable');

        $this->assertDatabaseHas('units', ['id' => $unit->id, 'sale_status' => 'unavailable']);
    }

    public function test_make_unavailable_is_refused_for_a_held_or_sold_unit(): void
    {
        $sold = Unit::factory()->sold()->create();
        $interested = Unit::factory()->interested()->create();
        Sanctum::actingAs($this->manager());

        $this->postJson("/api/v1/units/{$sold->id}/unavailable")->assertStatus(422);
        $this->postJson("/api/v1/units/{$interested->id}/unavailable")->assertStatus(422);

        $this->assertDatabaseHas('units', ['id' => $sold->id, 'sale_status' => 'sold']);
        $this->assertDatabaseHas('units', ['id' => $interested->id, 'sale_status' => 'interested']);
    }

    public function test_make_available_brings_a_parked_unit_back(): void
    {
        $unit = Unit::factory()->unavailable()->create();
        Sanctum::actingAs($this->manager());

        $this->postJson("/api/v1/units/{$unit->id}/available")
            ->assertOk()
            ->assertJsonPath('data.sale_status', 'available');

        $this->assertDatabaseHas('units', ['id' => $unit->id, 'sale_status' => 'available']);
    }

    public function test_make_available_is_refused_unless_the_unit_is_parked(): void
    {
        $unit = Unit::factory()->create(); // already available
        Sanctum::actingAs($this->manager());

        $this->postJson("/api/v1/units/{$unit->id}/available")->assertStatus(422);
    }

    public function test_toggles_require_units_manage(): void
    {
        $unit = Unit::factory()->create();
        Sanctum::actingAs($this->userWithPermissions(['units.view']));

        $this->postJson("/api/v1/units/{$unit->id}/unavailable")->assertForbidden();
        $this->assertDatabaseHas('units', ['id' => $unit->id, 'sale_status' => 'available']);
    }

    // ── Bulk park / un-park ─────────────────────────────────────────────

    public function test_bulk_unavailable_parks_available_units_and_skips_the_rest(): void
    {
        $location = Location::factory()->create();
        $available = Unit::factory()->count(2)->create(['location_id' => $location->id]);
        $sold = Unit::factory()->sold()->create(['location_id' => $location->id]);

        Sanctum::actingAs($this->manager());

        $response = $this->postJson('/api/v1/units/bulk-unavailable', [
            'ids' => [...$available->pluck('id'), $sold->id, 999999],
        ])->assertOk();

        $this->assertSame(2, $response->json('data.changed'));
        $skipped = collect($response->json('data.skipped'));
        $this->assertSame('not_eligible', $skipped->firstWhere('id', $sold->id)['reason']);
        $this->assertSame('not_found', $skipped->firstWhere('id', 999999)['reason']);

        foreach ($available as $unit) {
            $this->assertDatabaseHas('units', ['id' => $unit->id, 'sale_status' => 'unavailable']);
        }
        $this->assertDatabaseHas('units', ['id' => $sold->id, 'sale_status' => 'sold']);
    }

    public function test_bulk_available_restores_only_parked_units(): void
    {
        $parked = Unit::factory()->unavailable()->count(2)->create();
        $live = Unit::factory()->create();

        Sanctum::actingAs($this->manager());

        $response = $this->postJson('/api/v1/units/bulk-available', [
            'ids' => [...$parked->pluck('id'), $live->id],
        ])->assertOk();

        $this->assertSame(2, $response->json('data.changed'));
        $this->assertCount(1, $response->json('data.skipped'));
        foreach ($parked as $unit) {
            $this->assertDatabaseHas('units', ['id' => $unit->id, 'sale_status' => 'available']);
        }
    }

    // ── Selector visibility ─────────────────────────────────────────────

    public function test_selectable_units_browse_hides_parked_units_and_projects(): void
    {
        $liveProject = Location::factory()->create();
        $parkedProject = Location::factory()->create(['is_available' => false]);

        $available = Unit::factory()->create(['location_id' => $liveProject->id]);
        $parkedUnit = Unit::factory()->unavailable()->create(['location_id' => $liveProject->id]);
        $unitInParkedProject = Unit::factory()->create(['location_id' => $parkedProject->id]);

        Sanctum::actingAs($this->manager());

        // Selector mode: only the one live, available unit.
        $ids = collect($this->getJson('/api/v1/units?selectable=1')->assertOk()->json('data'))
            ->pluck('id');
        $this->assertTrue($ids->contains($available->id));
        $this->assertFalse($ids->contains($parkedUnit->id));
        $this->assertFalse($ids->contains($unitInParkedProject->id));

        // Management browse (no selectable): everything active still shows.
        $allIds = collect($this->getJson('/api/v1/units')->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($allIds->contains($parkedUnit->id));
        $this->assertTrue($allIds->contains($unitInParkedProject->id));
    }

    public function test_non_managers_never_see_parked_stock_in_any_browse(): void
    {
        $liveProject = Location::factory()->create();
        $parkedProject = Location::factory()->create(['is_available' => false]);

        $available = Unit::factory()->create(['location_id' => $liveProject->id]);
        $parkedUnit = Unit::factory()->unavailable()->create(['location_id' => $liveProject->id]);
        // The project veil hides EVERY unit inside it, whatever its own state.
        $availableInParkedProject = Unit::factory()->create(['location_id' => $parkedProject->id]);
        $soldInParkedProject = Unit::factory()->sold()->create(['location_id' => $parkedProject->id]);

        // units.view only — no manage grant, so the plain browse (the Units
        // table) applies the parked veil exactly like the selectors do.
        Sanctum::actingAs($this->userWithPermissions(['units.view']));

        $ids = collect($this->getJson('/api/v1/units')->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($available->id));
        $this->assertFalse($ids->contains($parkedUnit->id));
        $this->assertFalse($ids->contains($availableInParkedProject->id));
        $this->assertFalse($ids->contains($soldInParkedProject->id));
    }
}
