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

class StackingTest extends TestCase
{
    use RefreshDatabase;

    private function viewer(): User
    {
        $role = Role::factory()->create();
        $role->permissions()->sync(
            Permission::firstOrCreate(['slug' => 'units.view'], ['name' => 'units.view'])->id
        );

        return User::factory()->create(['role_id' => $role->id]);
    }

    public function test_stacking_plan_groups_units_by_block_and_floor_with_live_status(): void
    {
        $location = Location::factory()->create();
        Unit::factory()->for($location)->create(['block' => 'A', 'stack_floor' => 2, 'position' => 1, 'reference' => 'A-201', 'sale_status' => 'available']);
        Unit::factory()->for($location)->reserved()->create(['block' => 'A', 'stack_floor' => 1, 'position' => 1, 'reference' => 'A-101']);
        Unit::factory()->for($location)->sold()->create(['block' => 'B', 'stack_floor' => 1, 'position' => 1, 'reference' => 'B-101']);

        Sanctum::actingAs($this->viewer());

        $data = $this->getJson("/api/v1/locations/{$location->id}/stacking")
            ->assertOk()
            ->json('data');

        // Two blocks, floors ordered top-down within a block.
        $this->assertCount(2, $data);
        $this->assertSame('A', $data[0]['block']);
        $this->assertSame(2, $data[0]['floors'][0]['floor']); // top floor first
        $this->assertSame('reserved', $data[0]['floors'][1]['units'][0]['sale_status']);
        $this->assertSame('sold', $data[1]['floors'][0]['units'][0]['sale_status']);
    }

    public function test_cancelled_units_are_excluded_from_the_plan(): void
    {
        $location = Location::factory()->create();
        $unit = Unit::factory()->for($location)->create(['block' => 'A', 'stack_floor' => 1, 'position' => 1]);
        $unit->cancel('gone');

        Sanctum::actingAs($this->viewer());

        $this->getJson("/api/v1/locations/{$location->id}/stacking")
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_requires_authentication(): void
    {
        $location = Location::factory()->create();

        $this->getJson("/api/v1/locations/{$location->id}/stacking")->assertUnauthorized();
    }
}
