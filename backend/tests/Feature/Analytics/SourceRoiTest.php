<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Modules\Clients\Enums\ClientProjectStage;
use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Settings\Models\DynamicListItem;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SourceRoiTest extends TestCase
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

    public function test_a_won_sale_is_attributed_to_the_correct_source(): void
    {
        $sourceA = DynamicListItem::factory()->create();
        $sourceB = DynamicListItem::factory()->create();

        $clientA = Client::factory()->create(['source_id' => $sourceA->id]);
        $clientB = Client::factory()->create(['source_id' => $sourceB->id]);

        ClientProject::factory()->stage(ClientProjectStage::Won)->create([
            'client_id' => $clientA->id,
            'total_price' => '100000.00',
        ]);
        ClientProject::factory()->stage(ClientProjectStage::Lead)->create([
            'client_id' => $clientB->id,
        ]);

        Sanctum::actingAs($this->user(['dashboard.view', 'reports.view']));

        $data = collect($this->getJson('/api/v1/analytics/source-roi')->assertOk()->json('data'));

        $rowA = $data->firstWhere('source_id', $sourceA->id);
        $rowB = $data->firstWhere('source_id', $sourceB->id);

        $this->assertSame(1, $rowA['leads']);
        $this->assertSame(1, $rowA['won']);
        $this->assertSame('100000.00', $rowA['revenue']);
        $this->assertEqualsWithDelta(100.0, $rowA['conversion'], 0.01);

        $this->assertSame(0, $rowB['won']);
        $this->assertSame('0.00', $rowB['revenue']);
    }

    public function test_source_roi_requires_the_reports_permission(): void
    {
        Sanctum::actingAs($this->user(['dashboard.view']));

        $this->getJson('/api/v1/analytics/source-roi')->assertForbidden();
    }
}
