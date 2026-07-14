<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Inventory\Support\UnitsWorkbook;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Once a unit is SOLD its asking price becomes privileged (units.sold_price):
 * viewers without the grant get both prices nulled (`prices_masked`) on every
 * surface — browse/detail resources, the stacking plan, the insights stats
 * block and the Excel export. Unsold units are never masked.
 */
class UnitSoldPriceVisibilityTest extends TestCase
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

    public function test_sold_prices_are_masked_in_browse_and_detail_without_the_grant(): void
    {
        $location = Location::factory()->create();
        $sold = Unit::factory()->sold()->dualPrice()->create(['location_id' => $location->id]);
        $available = Unit::factory()->create(['location_id' => $location->id]);

        Sanctum::actingAs($this->userWithPermissions(['units.view']));

        $rows = collect($this->getJson('/api/v1/units')->assertOk()->json('data'))->keyBy('id');

        $this->assertNull($rows[$sold->id]['price_semi_fini']);
        $this->assertNull($rows[$sold->id]['price_fini']);
        $this->assertTrue($rows[$sold->id]['prices_masked']);

        $this->assertNotNull($rows[$available->id]['price_semi_fini']);
        $this->assertFalse($rows[$available->id]['prices_masked']);

        $this->getJson("/api/v1/units/{$sold->id}")
            ->assertOk()
            ->assertJsonPath('data.price_semi_fini', null)
            ->assertJsonPath('data.price_fini', null)
            ->assertJsonPath('data.prices_masked', true);
    }

    public function test_the_grant_reveals_sold_prices(): void
    {
        $sold = Unit::factory()->sold()->create(['price_semi_fini' => 7000000]);

        Sanctum::actingAs($this->userWithPermissions(['units.view', 'units.sold_price']));

        $this->getJson("/api/v1/units/{$sold->id}")
            ->assertOk()
            ->assertJsonPath('data.price_semi_fini', '7000000.00')
            ->assertJsonPath('data.prices_masked', false);
    }

    public function test_the_stacking_plan_masks_sold_prices_without_the_grant(): void
    {
        $location = Location::factory()->create();
        $sold = Unit::factory()->sold()->create(['location_id' => $location->id, 'block' => 'A']);
        $available = Unit::factory()->create(['location_id' => $location->id, 'block' => 'A']);

        Sanctum::actingAs($this->userWithPermissions(['units.view']));

        $cells = collect($this->getJson("/api/v1/locations/{$location->id}/stacking")->assertOk()->json('data'))
            ->flatMap(fn ($block) => collect($block['floors'])->flatMap(fn ($floor) => $floor['units']))
            ->keyBy('id');

        $this->assertNull($cells[$sold->id]['price']);
        $this->assertNotNull($cells[$available->id]['price']);

        // With the grant the sold cell keeps its price.
        Sanctum::actingAs($this->userWithPermissions(['units.view', 'units.sold_price']));
        $cells = collect($this->getJson("/api/v1/locations/{$location->id}/stacking")->assertOk()->json('data'))
            ->flatMap(fn ($block) => collect($block['floors'])->flatMap(fn ($floor) => $floor['units']))
            ->keyBy('id');
        $this->assertNotNull($cells[$sold->id]['price']);
    }

    public function test_insights_stats_mask_sold_prices_even_for_stats_holders(): void
    {
        $sold = Unit::factory()->sold()->create(['price_semi_fini' => 6000000]);

        Sanctum::actingAs($this->userWithPermissions(['units.view', 'units.stats']));

        $this->getJson("/api/v1/units/{$sold->id}/insights")
            ->assertOk()
            ->assertJsonPath('data.stats.price_semi_fini', null);

        Sanctum::actingAs($this->userWithPermissions(['units.view', 'units.stats', 'units.sold_price']));

        $this->getJson("/api/v1/units/{$sold->id}/insights")
            ->assertOk()
            ->assertJsonPath('data.stats.price_semi_fini', '6000000.00');
    }

    public function test_the_export_blanks_sold_price_cells_when_masking(): void
    {
        $sold = Unit::factory()->sold()->dualPrice()->create();

        // price_semi_fini / price_fini are columns J / K of EXPORT_COLUMNS.
        $masked = (new UnitsWorkbook)->export(collect([$sold]), maskSoldPrices: true)->getActiveSheet();
        $this->assertNull($masked->getCell('J2')->getValue());
        $this->assertNull($masked->getCell('K2')->getValue());

        $open = (new UnitsWorkbook)->export(collect([$sold]), maskSoldPrices: false)->getActiveSheet();
        $this->assertEquals((float) $sold->price_semi_fini, (float) $open->getCell('J2')->getValue());
    }
}
