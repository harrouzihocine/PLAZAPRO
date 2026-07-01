<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Models\Box;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BoxTest extends TestCase
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

    public function test_manager_can_create_a_box_optionally_linked_to_a_unit(): void
    {
        $location = Location::factory()->create();
        $unit = Unit::factory()->for($location)->create();
        Sanctum::actingAs($this->manager());

        $this->postJson("/api/v1/locations/{$location->id}/boxes", [
            'reference' => 'P-12',
            'price' => 15000,
            'unit_id' => $unit->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.reference', 'P-12')
            ->assertJsonPath('data.unit_id', $unit->id)
            ->assertJsonPath('data.sale_status', 'available');
    }

    public function test_linked_unit_must_belong_to_the_same_location(): void
    {
        $location = Location::factory()->create();
        $otherUnit = Unit::factory()->create(); // different location
        Sanctum::actingAs($this->manager());

        $this->postJson("/api/v1/locations/{$location->id}/boxes", [
            'reference' => 'P-9', 'price' => 1, 'unit_id' => $otherUnit->id,
        ])->assertStatus(422)->assertJsonValidationErrorFor('unit_id');
    }

    public function test_reference_unique_among_active_boxes_in_location(): void
    {
        $location = Location::factory()->create();
        Box::factory()->for($location)->create(['reference' => 'P-1']);
        Sanctum::actingAs($this->manager());

        $this->postJson("/api/v1/locations/{$location->id}/boxes", ['reference' => 'P-1', 'price' => 1])
            ->assertStatus(422)->assertJsonValidationErrorFor('reference');
    }

    public function test_index_filters_by_location(): void
    {
        $a = Location::factory()->create();
        $b = Location::factory()->create();
        Box::factory()->for($a)->create(['reference' => 'A-1']);
        Box::factory()->for($b)->create(['reference' => 'B-1']);
        Sanctum::actingAs($this->manager());

        $this->getJson("/api/v1/boxes?location_id={$a->id}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.reference', 'A-1');
    }

    public function test_writes_require_units_manage(): void
    {
        $location = Location::factory()->create();
        Sanctum::actingAs($this->userWithPermissions(['units.view']));

        $this->postJson("/api/v1/locations/{$location->id}/boxes", ['reference' => 'X', 'price' => 1])
            ->assertForbidden();
    }

    public function test_cancelling_a_box_keeps_the_row(): void
    {
        $box = Box::factory()->create();
        Sanctum::actingAs($this->manager());

        $this->deleteJson("/api/v1/boxes/{$box->id}")->assertOk();
        $this->assertDatabaseHas('boxes', ['id' => $box->id, 'status' => 'cancelled']);
    }
}
