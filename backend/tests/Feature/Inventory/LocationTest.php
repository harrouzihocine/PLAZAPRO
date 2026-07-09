<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Media;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Settings\Models\DynamicListItem;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LocationTest extends TestCase
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

    public function test_manager_can_create_a_location(): void
    {
        Sanctum::actingAs($this->manager());

        $this->postJson('/api/v1/locations', [
            'name' => 'Résidence Les Oliviers',
            'code' => 'OLIV-1',
            'address' => '12 rue des Oliviers',
            'expected_delivery_date' => '2027-09-01',
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Résidence Les Oliviers')
            ->assertJsonPath('data.code', 'OLIV-1')
            ->assertJsonPath('data.expected_delivery_date', '2027-09-01');

        $this->assertDatabaseHas('locations', [
            'code' => 'OLIV-1', 'status' => 'active', 'expected_delivery_date' => '2027-09-01',
        ]);
    }

    public function test_expected_delivery_date_must_be_a_valid_date(): void
    {
        Sanctum::actingAs($this->manager());

        $this->postJson('/api/v1/locations', [
            'name' => 'X', 'code' => 'BAD-1', 'expected_delivery_date' => 'not-a-date',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('expected_delivery_date');
    }

    public function test_location_defaults_to_medium_gtm_priority(): void
    {
        Sanctum::actingAs($this->manager());

        $this->postJson('/api/v1/locations', ['name' => 'X', 'code' => 'PRIO-1'])
            ->assertCreated()
            ->assertJsonPath('data.gtm_priority', 'medium');
    }

    public function test_manager_can_set_and_filter_by_gtm_priority(): void
    {
        Sanctum::actingAs($this->manager());

        $this->postJson('/api/v1/locations', [
            'name' => 'Flagship', 'code' => 'HIGH-1', 'gtm_priority' => 'critical',
        ])
            ->assertCreated()
            ->assertJsonPath('data.gtm_priority', 'critical');

        Location::factory()->create(['name' => 'Slow', 'code' => 'LOW-1', 'gtm_priority' => 'low']);

        $this->getJson('/api/v1/locations?priority=critical')
            ->assertOk()
            ->assertJsonFragment(['code' => 'HIGH-1'])
            ->assertJsonMissing(['code' => 'LOW-1']);
    }

    public function test_unit_filters_narrow_the_project_list_to_matching_inventory(): void
    {
        Sanctum::actingAs($this->manager());

        $floorA = DynamicListItem::factory()->create();
        $floorB = DynamicListItem::factory()->create();

        $withMatch = Location::factory()->create(['code' => 'HIT-1']);
        Unit::factory()->for($withMatch)->create(['sale_status' => 'available', 'floor_id' => $floorA->id, 'price_semi_fini' => 5000000]);

        // Right floor but SOLD — not purchasable, so the project drops out.
        $soldOnly = Location::factory()->create(['code' => 'SOLD-1']);
        Unit::factory()->for($soldOnly)->create(['sale_status' => 'sold', 'floor_id' => $floorA->id, 'price_semi_fini' => 5000000]);

        $wrongFloor = Location::factory()->create(['code' => 'MISS-1']);
        Unit::factory()->for($wrongFloor)->create(['sale_status' => 'available', 'floor_id' => $floorB->id, 'price_semi_fini' => 5000000]);

        $this->getJson('/api/v1/locations?unit_floor_id[]='.$floorA->id)
            ->assertOk()
            ->assertJsonFragment(['code' => 'HIT-1'])
            ->assertJsonMissing(['code' => 'SOLD-1'])
            ->assertJsonMissing(['code' => 'MISS-1']);

        // A price band on top of the floor filter — the unit falls below it.
        $this->getJson('/api/v1/locations?unit_floor_id[]='.$floorA->id.'&unit_min_price=6000000')
            ->assertOk()
            ->assertJsonMissing(['code' => 'HIT-1']);

        // No unit_* params → the full list, sold-only projects included.
        $this->getJson('/api/v1/locations')
            ->assertOk()
            ->assertJsonFragment(['code' => 'SOLD-1']);
    }

    public function test_gtm_priority_must_be_a_valid_degree(): void
    {
        Sanctum::actingAs($this->manager());

        $this->postJson('/api/v1/locations', [
            'name' => 'X', 'code' => 'BADP-1', 'gtm_priority' => 'sky-high',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('gtm_priority');
    }

    public function test_manager_can_set_a_contract_type(): void
    {
        $contractType = DynamicListItem::factory()->create(['label' => 'VEFA (off-plan)']);
        Sanctum::actingAs($this->manager());

        $this->postJson('/api/v1/locations', [
            'name' => 'Résidence VEFA', 'code' => 'VEFA-1', 'contract_type_id' => $contractType->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.contract_type_id', $contractType->id)
            ->assertJsonPath('data.contract_type', 'VEFA (off-plan)');

        $this->assertDatabaseHas('locations', [
            'code' => 'VEFA-1', 'contract_type_id' => $contractType->id,
        ]);
    }

    public function test_manager_can_set_a_cover_picture(): void
    {
        $location = Location::factory()->create();
        $media = Media::factory()->create();
        Sanctum::actingAs($this->manager());

        $this->putJson("/api/v1/locations/{$location->id}", ['cover_media_id' => $media->id])
            ->assertOk()
            ->assertJsonPath('data.cover_media_id', $media->id);

        $this->assertDatabaseHas('locations', [
            'id' => $location->id, 'cover_media_id' => $media->id,
        ]);
    }

    public function test_contract_type_must_reference_an_existing_list_item(): void
    {
        Sanctum::actingAs($this->manager());

        $this->postJson('/api/v1/locations', [
            'name' => 'X', 'code' => 'CT-BAD-1', 'contract_type_id' => 999999,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('contract_type_id');
    }

    public function test_manager_can_clear_the_contract_type(): void
    {
        $contractType = DynamicListItem::factory()->create();
        $location = Location::factory()->create(['contract_type_id' => $contractType->id]);
        Sanctum::actingAs($this->manager());

        $this->putJson("/api/v1/locations/{$location->id}", ['contract_type_id' => null])
            ->assertOk()
            ->assertJsonPath('data.contract_type_id', null)
            ->assertJsonPath('data.contract_type', null);

        $this->assertDatabaseHas('locations', [
            'id' => $location->id, 'contract_type_id' => null,
        ]);
    }

    public function test_index_includes_the_contract_type_label(): void
    {
        $contractType = DynamicListItem::factory()->create(['label' => 'Turnkey (ready)']);
        Location::factory()->create(['name' => 'Le Parc', 'contract_type_id' => $contractType->id]);
        Sanctum::actingAs($this->userWithPermissions(['units.view']));

        $this->getJson('/api/v1/locations')
            ->assertOk()
            ->assertJsonFragment(['contract_type' => 'Turnkey (ready)']);
    }

    public function test_manager_can_update_the_expected_delivery_date(): void
    {
        $location = Location::factory()->create(['expected_delivery_date' => null]);
        Sanctum::actingAs($this->manager());

        $this->putJson("/api/v1/locations/{$location->id}", ['expected_delivery_date' => '2028-01-15'])
            ->assertOk()
            ->assertJsonPath('data.expected_delivery_date', '2028-01-15');

        $this->assertDatabaseHas('locations', [
            'id' => $location->id, 'expected_delivery_date' => '2028-01-15',
        ]);
    }

    public function test_code_must_be_unique(): void
    {
        Location::factory()->create(['code' => 'DUP-1']);
        Sanctum::actingAs($this->manager());

        $this->postJson('/api/v1/locations', ['name' => 'X', 'code' => 'DUP-1'])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('code');
    }

    public function test_index_is_readable_by_any_user_with_units_view(): void
    {
        Location::factory()->create(['name' => 'Le Parc']);
        Sanctum::actingAs($this->userWithPermissions(['units.view']));

        $this->getJson('/api/v1/locations')->assertOk()->assertJsonFragment(['name' => 'Le Parc']);
    }

    public function test_writes_require_locations_manage_permission(): void
    {
        Sanctum::actingAs($this->userWithPermissions(['units.view']));

        $this->postJson('/api/v1/locations', ['name' => 'X', 'code' => 'Y-1'])->assertForbidden();
    }

    public function test_cancelling_a_location_keeps_the_row(): void
    {
        $location = Location::factory()->create();
        Sanctum::actingAs($this->manager());

        $this->deleteJson("/api/v1/locations/{$location->id}")->assertOk();

        // No hard delete — the row survives with a cancelled status.
        $this->assertDatabaseHas('locations', ['id' => $location->id, 'status' => 'cancelled']);
    }

    public function test_cancelled_locations_are_hidden_by_default_but_visible_with_status_all(): void
    {
        $location = Location::factory()->create();
        Sanctum::actingAs($this->manager());
        $this->deleteJson("/api/v1/locations/{$location->id}")->assertOk();

        $this->getJson('/api/v1/locations')
            ->assertOk()
            ->assertJsonMissing(['id' => $location->id]);

        $this->getJson('/api/v1/locations?status=all')
            ->assertOk()
            ->assertJsonFragment(['id' => $location->id]);
    }
}
