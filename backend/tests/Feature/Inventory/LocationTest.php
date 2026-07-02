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

    public function test_gtm_priority_must_be_a_valid_degree(): void
    {
        Sanctum::actingAs($this->manager());

        $this->postJson('/api/v1/locations', [
            'name' => 'X', 'code' => 'BADP-1', 'gtm_priority' => 'sky-high',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('gtm_priority');
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
