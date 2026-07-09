<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Modules\Clients\Enums\DealState;
use App\Modules\Clients\Models\DealItem;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The KPI command center is gated by analytics.kpi and must honour the
 * cancel-and-duplicate rule: a cancelled won sale never counts as sold, only as
 * a cancellation. These are the two invariants everything else builds on.
 */
class KpiDashboardTest extends TestCase
{
    use RefreshDatabase;

    /** @param list<string> $permissions */
    private function user(array $permissions): User
    {
        $role = Role::factory()->create();
        $role->permissions()->sync(
            collect($permissions)->map(fn (string $slug) => Permission::firstOrCreate(['slug' => $slug], ['name' => $slug])->id),
        );

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function wonItem(string $price, array $overrides = []): DealItem
    {
        return DealItem::factory()->create(array_merge([
            'state' => DealState::Won->value,
            'agreed_price' => $price,
            'closed_at' => now(),
        ], $overrides));
    }

    public function test_kpi_board_requires_the_analytics_kpi_permission(): void
    {
        Sanctum::actingAs($this->user(['dashboard.view']));
        $this->getJson('/api/v1/analytics/kpi/overview')->assertForbidden();

        Sanctum::actingAs($this->user(['analytics.kpi']));
        $this->getJson('/api/v1/analytics/kpi/overview')->assertOk();
    }

    public function test_sales_value_sums_won_items_in_the_window(): void
    {
        $this->wonItem('100000.00');
        $this->wonItem('50000.00');

        Sanctum::actingAs($this->user(['analytics.kpi']));

        $data = $this->getJson('/api/v1/analytics/kpi/sales?period=year')->assertOk()->json('data');

        $this->assertSame('150000.00', $data['sales_value']['value']);
        $this->assertSame(2, $data['units_sold']['total']);
    }

    public function test_a_cancelled_sale_is_excluded_from_sales_but_counted_as_a_cancellation(): void
    {
        $this->wonItem('100000.00');                       // active sale
        $this->wonItem('50000.00', ['status' => 'cancelled']); // cancelled sale — history kept

        Sanctum::actingAs($this->user(['analytics.kpi']));

        $sales = $this->getJson('/api/v1/analytics/kpi/sales?period=year')->assertOk()->json('data');
        // Cancelled row is NOT sold value; net subtracts it.
        $this->assertSame('100000.00', $sales['sales_value']['value']);
        $this->assertSame(1, $sales['units_sold']['total']);
        $this->assertSame('50000.00', $sales['net_sales_value']['cancelled']);

        $cancel = $this->getJson('/api/v1/analytics/kpi/cancellations?period=year')->assertOk()->json('data');
        $this->assertSame(1, $cancel['cancelled']);
        $this->assertSame('50000.00', $cancel['value_cancelled']);
        $this->assertSame(1, $cancel['won']); // the active one
    }

    public function test_location_filter_narrows_sales_to_one_development(): void
    {
        $a = $this->wonItem('100000.00');
        $this->wonItem('40000.00'); // different auto-created unit/location

        $locationId = $a->unit->location_id;

        Sanctum::actingAs($this->user(['analytics.kpi']));

        $data = $this->getJson("/api/v1/analytics/kpi/sales?period=year&location_id={$locationId}")
            ->assertOk()->json('data');

        $this->assertSame('100000.00', $data['sales_value']['value']);
    }
}
