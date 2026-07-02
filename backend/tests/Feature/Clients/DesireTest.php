<?php

declare(strict_types=1);

namespace Tests\Feature\Clients;

use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\Desire;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Settings\Models\DynamicListItem;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DesireTest extends TestCase
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

    private function agent(): User
    {
        return $this->userWithPermissions(['clients.view', 'clients.create', 'units.view']);
    }

    public function test_desire_is_upserted_one_per_client(): void
    {
        $client = Client::factory()->create();
        Sanctum::actingAs($this->agent());

        $this->putJson("/api/v1/clients/{$client->id}/desire", ['floor_pref' => 'floor_2', 'budget_max' => 5000000])
            ->assertOk()
            ->assertJsonPath('data.floor_pref', 'floor_2');

        // A second upsert updates the same row rather than creating a new one.
        $this->putJson("/api/v1/clients/{$client->id}/desire", ['floor_pref' => 'floor_3'])
            ->assertOk()
            ->assertJsonPath('data.floor_pref', 'floor_3');

        $this->assertSame(1, Desire::where('client_id', $client->id)->count());
    }

    public function test_max_budget_below_min_is_rejected(): void
    {
        $client = Client::factory()->create();
        Sanctum::actingAs($this->agent());

        $this->putJson("/api/v1/clients/{$client->id}/desire", ['budget_min' => 5000000, 'budget_max' => 1000000])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('budget_max');
    }

    public function test_matching_returns_only_available_units_that_fit(): void
    {
        $area = DynamicListItem::factory()->create();
        $type = DynamicListItem::factory()->create();
        $otherType = DynamicListItem::factory()->create();

        $location = Location::factory()->create(['area_id' => $area->id]);
        $otherLocation = Location::factory()->create(['area_id' => null]);

        // The one true match.
        $match = Unit::factory()->for($location)->create([
            'reference' => 'M-1', 'sale_status' => 'available',
            'type_id' => $type->id, 'price' => 5000000,
        ]);
        // Excluded for various reasons.
        Unit::factory()->for($location)->create(['reference' => 'X-sold', 'sale_status' => 'sold', 'type_id' => $type->id, 'price' => 5000000]);
        Unit::factory()->for($location)->create(['reference' => 'X-pricey', 'sale_status' => 'available', 'type_id' => $type->id, 'price' => 99000000]);
        Unit::factory()->for($location)->create(['reference' => 'X-type', 'sale_status' => 'available', 'type_id' => $otherType->id, 'price' => 5000000]);
        Unit::factory()->for($otherLocation)->create(['reference' => 'X-area', 'sale_status' => 'available', 'type_id' => $type->id, 'price' => 5000000]);

        $client = Client::factory()->create();
        Desire::factory()->create([
            'client_id' => $client->id,
            'area_id' => $area->id, 'type_id' => $type->id,
            'budget_min' => 1000000, 'budget_max' => 10000000,
        ]);

        Sanctum::actingAs($this->agent());

        $this->getJson("/api/v1/clients/{$client->id}/matches")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $match->id);
    }

    public function test_matches_are_ranked_by_closeness_to_budget(): void
    {
        $type = DynamicListItem::factory()->create();
        $location = Location::factory()->create();

        // Budget 2M–6M → midpoint 4M. The unit nearest 4M should rank first.
        $near = Unit::factory()->for($location)->create(['reference' => 'N', 'sale_status' => 'available', 'type_id' => $type->id, 'price' => 4100000]);
        Unit::factory()->for($location)->create(['reference' => 'F', 'sale_status' => 'available', 'type_id' => $type->id, 'price' => 5900000]);

        $client = Client::factory()->create();
        Desire::factory()->create([
            'client_id' => $client->id, 'type_id' => $type->id,
            'budget_min' => 2000000, 'budget_max' => 6000000,
        ]);

        Sanctum::actingAs($this->agent());

        $this->getJson("/api/v1/clients/{$client->id}/matches")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $near->id);
    }

    public function test_matches_require_units_view(): void
    {
        $client = Client::factory()->create();
        Sanctum::actingAs($this->userWithPermissions(['clients.view']));

        $this->getJson("/api/v1/clients/{$client->id}/matches")->assertForbidden();
    }

    public function test_writing_desire_requires_clients_create(): void
    {
        $client = Client::factory()->create();
        Sanctum::actingAs($this->userWithPermissions(['clients.view']));

        $this->putJson("/api/v1/clients/{$client->id}/desire", ['floor_pref' => 'floor_2'])->assertForbidden();
    }
}
