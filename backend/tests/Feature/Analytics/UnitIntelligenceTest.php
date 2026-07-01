<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Modules\Clients\Enums\ClientProjectStage;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Inventory\Models\Reservation;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Pipeline\Models\Visit;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UnitIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  list<string>  $permissions
     */
    private function user(array $permissions): User
    {
        $role = Role::factory()->create();
        $role->permissions()->sync(
            collect($permissions)->map(fn (string $slug) => Permission::firstOrCreate(['slug' => $slug], ['name' => $slug])->id)
        );

        return User::factory()->create(['role_id' => $role->id]);
    }

    public function test_it_reports_interest_holds_and_conversion_per_unit(): void
    {
        $unit = Unit::factory()->create();

        Visit::factory()->count(2)->create(['unit_id' => $unit->id]);
        Reservation::factory()->create(['unit_id' => $unit->id]);
        ClientProject::factory()->stage(ClientProjectStage::Won)->create(['unit_id' => $unit->id]);

        Sanctum::actingAs($this->user(['dashboard.view', 'reports.view']));

        $data = collect($this->getJson('/api/v1/analytics/units')->assertOk()->json('data'));
        $row = $data->firstWhere('id', $unit->id);

        $this->assertSame(2, $row['visits']);
        $this->assertSame(1, $row['holds']);
        $this->assertSame(1, $row['won']);
        $this->assertEqualsWithDelta(50.0, $row['conversion'], 0.01);
    }

    public function test_unit_intelligence_requires_the_reports_permission(): void
    {
        Sanctum::actingAs($this->user(['dashboard.view']));

        $this->getJson('/api/v1/analytics/units')->assertForbidden();
    }
}
