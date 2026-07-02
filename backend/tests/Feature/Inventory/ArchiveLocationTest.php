<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Enums\SaleStatus;
use App\Modules\Inventory\Models\Box;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The project (location) lifecycle beyond active, both cascading to the project's
 * units + boxes:
 *   - Archive (POST /archive)   → reversible; hidden until reactivated. Safe over
 *                                 reserved/sold inventory (sale_status untouched).
 *   - Remove  (DELETE)          → terminal; refused while a unit/box is reserved or
 *                                 sold, else cancels the project + its inventory.
 */
class ArchiveLocationTest extends TestCase
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

    // --- Archive: reversible, hidden, cascades ---

    public function test_archiving_a_project_hides_it_and_its_inventory(): void
    {
        $location = Location::factory()->create();
        $unit = Unit::factory()->create(['location_id' => $location->id]);
        $box = Box::factory()->create(['location_id' => $location->id]);
        Sanctum::actingAs($this->manager());

        $this->postJson("/api/v1/locations/{$location->id}/archive")->assertOk();

        $this->assertDatabaseHas('locations', ['id' => $location->id, 'status' => 'archived']);
        $this->assertDatabaseHas('units', ['id' => $unit->id, 'status' => 'archived']);
        $this->assertDatabaseHas('boxes', ['id' => $box->id, 'status' => 'archived']);

        $this->getJson('/api/v1/locations')->assertOk()->assertJsonMissing(['id' => $location->id]);
        $this->getJson('/api/v1/locations?status=archived')->assertOk()->assertJsonFragment(['id' => $location->id]);
    }

    public function test_a_project_can_be_archived_even_with_sold_units(): void
    {
        $location = Location::factory()->create();
        $sold = Unit::factory()->create(['location_id' => $location->id, 'sale_status' => SaleStatus::Sold->value]);
        Sanctum::actingAs($this->manager());

        $this->postJson("/api/v1/locations/{$location->id}/archive")->assertOk();

        // Archiving is reversible, so it never touches sale_status.
        $this->assertDatabaseHas('units', [
            'id' => $sold->id, 'status' => 'archived', 'sale_status' => 'sold',
        ]);
    }

    public function test_reactivating_a_project_restores_it_and_its_inventory(): void
    {
        $location = Location::factory()->create();
        $unit = Unit::factory()->create(['location_id' => $location->id]);
        $box = Box::factory()->create(['location_id' => $location->id]);
        Sanctum::actingAs($this->manager());

        $this->postJson("/api/v1/locations/{$location->id}/archive")->assertOk();
        $this->postJson("/api/v1/locations/{$location->id}/reactivate")->assertOk();

        $this->assertDatabaseHas('locations', ['id' => $location->id, 'status' => 'active']);
        $this->assertDatabaseHas('units', ['id' => $unit->id, 'status' => 'active']);
        $this->assertDatabaseHas('boxes', ['id' => $box->id, 'status' => 'active']);
    }

    public function test_reactivate_does_not_revive_inventory_cancelled_before_the_archive(): void
    {
        $location = Location::factory()->create();
        $cancelled = Unit::factory()->create(['location_id' => $location->id, 'status' => 'cancelled']);
        $live = Unit::factory()->create(['location_id' => $location->id]);
        Sanctum::actingAs($this->manager());

        $this->postJson("/api/v1/locations/{$location->id}/archive")->assertOk();
        $this->postJson("/api/v1/locations/{$location->id}/reactivate")->assertOk();

        $this->assertDatabaseHas('units', ['id' => $live->id, 'status' => 'active']);
        $this->assertDatabaseHas('units', ['id' => $cancelled->id, 'status' => 'cancelled']);
    }

    // --- Remove: terminal, cascades over available inventory, blocks live sales ---

    public function test_removing_a_project_cancels_it_and_its_available_inventory(): void
    {
        $location = Location::factory()->create();
        $unit = Unit::factory()->create(['location_id' => $location->id]);
        $box = Box::factory()->create(['location_id' => $location->id]);
        Sanctum::actingAs($this->manager());

        $this->deleteJson("/api/v1/locations/{$location->id}")->assertOk();

        $this->assertDatabaseHas('locations', ['id' => $location->id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('units', ['id' => $unit->id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('boxes', ['id' => $box->id, 'status' => 'cancelled']);
    }

    public function test_removing_a_project_is_blocked_by_a_reserved_or_sold_unit(): void
    {
        $location = Location::factory()->create();
        $sold = Unit::factory()->create(['location_id' => $location->id, 'sale_status' => SaleStatus::Sold->value]);
        Sanctum::actingAs($this->manager());

        $this->deleteJson("/api/v1/locations/{$location->id}")->assertStatus(422);

        // Nothing is touched — the sale is protected.
        $this->assertDatabaseHas('locations', ['id' => $location->id, 'status' => 'active']);
        $this->assertDatabaseHas('units', ['id' => $sold->id, 'status' => 'active', 'sale_status' => 'sold']);
    }

    // --- Guards + permissions ---

    public function test_a_removed_project_cannot_be_archived(): void
    {
        $location = Location::factory()->create();
        Sanctum::actingAs($this->manager());

        $this->deleteJson("/api/v1/locations/{$location->id}")->assertOk();

        $this->postJson("/api/v1/locations/{$location->id}/archive")->assertStatus(422);
    }

    public function test_a_project_that_is_not_archived_cannot_be_reactivated(): void
    {
        $location = Location::factory()->create();
        Sanctum::actingAs($this->manager());

        $this->postJson("/api/v1/locations/{$location->id}/reactivate")->assertStatus(422);
    }

    public function test_archive_and_reactivate_require_locations_manage(): void
    {
        $location = Location::factory()->create();
        Sanctum::actingAs($this->userWithPermissions(['units.view']));

        $this->postJson("/api/v1/locations/{$location->id}/archive")->assertForbidden();
        $this->postJson("/api/v1/locations/{$location->id}/reactivate")->assertForbidden();
    }
}
