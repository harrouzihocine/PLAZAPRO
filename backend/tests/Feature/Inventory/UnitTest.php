<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Settings\Models\DynamicListItem;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UnitTest extends TestCase
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

    public function test_manager_can_create_a_unit_in_a_location(): void
    {
        $location = Location::factory()->create();
        Sanctum::actingAs($this->manager());

        $this->postJson("/api/v1/locations/{$location->id}/units", [
            'reference' => 'A-101',
            'price' => 250000,
        ])
            ->assertCreated()
            ->assertJsonPath('data.reference', 'A-101')
            ->assertJsonPath('data.sale_status', 'available');

        $this->assertDatabaseHas('units', [
            'location_id' => $location->id, 'reference' => 'A-101', 'status' => 'active',
        ]);
    }

    public function test_reference_must_be_unique_among_active_units_in_the_location(): void
    {
        $location = Location::factory()->create();
        Unit::factory()->for($location)->create(['reference' => 'A-101']);
        Sanctum::actingAs($this->manager());

        $this->postJson("/api/v1/locations/{$location->id}/units", ['reference' => 'A-101', 'price' => 1])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('reference');
    }

    public function test_same_reference_is_allowed_in_a_different_location(): void
    {
        $a = Location::factory()->create();
        $b = Location::factory()->create();
        Unit::factory()->for($a)->create(['reference' => 'A-101']);
        Sanctum::actingAs($this->manager());

        $this->postJson("/api/v1/locations/{$b->id}/units", ['reference' => 'A-101', 'price' => 1])
            ->assertCreated();
    }

    public function test_correcting_price_creates_a_new_version_and_keeps_the_old(): void
    {
        $unit = Unit::factory()->create(['price' => 200000, 'reference' => 'A-101']);
        Sanctum::actingAs($this->manager());

        $this->postJson("/api/v1/units/{$unit->id}/correct", [
            'price' => 220000,
            'reason' => 'Price list update',
        ])->assertOk()->assertJsonPath('data.price', '220000.00');

        // Old row cancelled, kept; new active version links back via supersedes_id.
        $this->assertDatabaseHas('units', ['id' => $unit->id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('units', [
            'reference' => 'A-101', 'status' => 'active', 'supersedes_id' => $unit->id,
        ]);
        // The reference is reused by the new active version without a unique clash.
        $this->assertSame(2, Unit::withoutGlobalScopes()->where('reference', 'A-101')->count());
    }

    public function test_index_filters_by_sale_status_and_price_range(): void
    {
        $location = Location::factory()->create();
        Unit::factory()->for($location)->create(['sale_status' => 'available', 'price' => 100000, 'reference' => 'A-1']);
        Unit::factory()->for($location)->sold()->create(['price' => 500000, 'reference' => 'A-2']);
        Sanctum::actingAs($this->manager());

        $this->getJson('/api/v1/units?sale_status=available')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', 'A-1');

        $this->getJson('/api/v1/units?min_price=300000')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', 'A-2');
    }

    public function test_index_filters_by_multiple_values_and_area_range(): void
    {
        $location = Location::factory()->create();
        Unit::factory()->for($location)->create(['sale_status' => 'available', 'area_sqm' => 60, 'reference' => 'A-1']);
        Unit::factory()->for($location)->reserved()->create(['area_sqm' => 90, 'reference' => 'A-2']);
        Unit::factory()->for($location)->sold()->create(['area_sqm' => 150, 'reference' => 'A-3']);
        Sanctum::actingAs($this->manager());

        // Multi-select statuses (available OR reserved).
        $this->getJson('/api/v1/units?sale_status[]=available&sale_status[]=reserved')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        // Area range keeps only the mid unit.
        $this->getJson('/api/v1/units?min_area=70&max_area=100')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', 'A-2');
    }

    public function test_index_filters_by_geographic_area_of_the_project(): void
    {
        $alger = DynamicListItem::factory()->create();
        $oran = DynamicListItem::factory()->create();
        $algerLocation = Location::factory()->create(['area_id' => $alger->id]);
        $oranLocation = Location::factory()->create(['area_id' => $oran->id]);
        Unit::factory()->for($algerLocation)->create(['reference' => 'A-1']);
        Unit::factory()->for($oranLocation)->create(['reference' => 'O-1']);
        Sanctum::actingAs($this->manager());

        // A unit's geographic area is its project's area_id.
        $this->getJson("/api/v1/units?area_id[]={$alger->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', 'A-1')
            ->assertJsonPath('data.0.location.area_id', $alger->id);
    }

    public function test_a_reserved_unit_cannot_be_cancelled(): void
    {
        $unit = Unit::factory()->reserved()->create();
        Sanctum::actingAs($this->manager());

        $this->deleteJson("/api/v1/units/{$unit->id}")->assertStatus(422);
        $this->assertDatabaseHas('units', ['id' => $unit->id, 'status' => 'active']);
    }

    public function test_writes_require_units_manage(): void
    {
        $location = Location::factory()->create();
        Sanctum::actingAs($this->userWithPermissions(['units.view']));

        $this->postJson("/api/v1/locations/{$location->id}/units", ['reference' => 'X', 'price' => 1])
            ->assertForbidden();
    }

    public function test_cannot_cancel_a_location_with_active_units(): void
    {
        $location = Location::factory()->create();
        Unit::factory()->for($location)->create();
        Sanctum::actingAs($this->userWithPermissions(['units.view', 'locations.manage']));

        $this->deleteJson("/api/v1/locations/{$location->id}")->assertStatus(422);
        $this->assertDatabaseHas('locations', ['id' => $location->id, 'status' => 'active']);
    }
}
