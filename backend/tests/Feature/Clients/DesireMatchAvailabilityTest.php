<?php

declare(strict_types=1);

namespace Tests\Feature\Clients;

use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\Desire;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use App\Modules\Settings\Models\Wilaya;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A unit parked off the market — either the unit itself (unavailable) or its
 * whole project (is_available = false) — must never surface as a desire match.
 */
class DesireMatchAvailabilityTest extends TestCase
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

    public function test_matches_exclude_parked_units_and_projects(): void
    {
        $wilaya = Wilaya::factory()->create();
        $liveProject = Location::factory()->create(['wilaya_id' => $wilaya->id]);
        $parkedProject = Location::factory()->create(['wilaya_id' => $wilaya->id, 'is_available' => false]);

        // The only unit that should match.
        $match = Unit::factory()->for($liveProject)->create([
            'reference' => 'M-1', 'sale_status' => 'available', 'price_semi_fini' => 5000000,
        ]);
        // Parked off the market — excluded despite fitting.
        Unit::factory()->for($liveProject)->unavailable()->create([
            'reference' => 'X-parked-unit', 'price_semi_fini' => 5000000,
        ]);
        Unit::factory()->for($parkedProject)->create([
            'reference' => 'X-parked-project', 'sale_status' => 'available', 'price_semi_fini' => 5000000,
        ]);

        $client = Client::factory()->create();
        $desire = Desire::factory()->create([
            'client_id' => $client->id,
            'budget_min' => 1000000, 'budget_max' => 10000000,
        ]);
        $desire->wilayas()->attach($wilaya->id);

        Sanctum::actingAs($this->agent());

        $this->getJson("/api/v1/clients/{$client->id}/matches")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $match->id);
    }
}
