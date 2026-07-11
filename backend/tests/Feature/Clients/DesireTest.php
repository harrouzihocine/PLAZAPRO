<?php

declare(strict_types=1);

namespace Tests\Feature\Clients;

use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\Desire;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Pipeline\Models\Call;
use App\Modules\Settings\Models\Commune;
use App\Modules\Settings\Models\DynamicListItem;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use App\Modules\Settings\Models\Wilaya;
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
        Call::factory()->create(['client_id' => $client->id]); // call-first rule
        Sanctum::actingAs($this->agent());

        $this->putJson("/api/v1/clients/{$client->id}/desire", ['floor_pref' => 'floor_2', 'budget_max' => 5000000, 'notes' => 'Ground-floor ok, max 5M'])
            ->assertOk()
            ->assertJsonPath('data.floor_pref', 'floor_2');

        // A second upsert updates the same row rather than creating a new one.
        $this->putJson("/api/v1/clients/{$client->id}/desire", ['floor_pref' => 'floor_3', 'notes' => 'Changed mind: third floor'])
            ->assertOk()
            ->assertJsonPath('data.floor_pref', 'floor_3');

        $this->assertSame(1, Desire::where('client_id', $client->id)->count());
    }

    public function test_max_budget_below_min_is_rejected(): void
    {
        $client = Client::factory()->create();
        Call::factory()->create(['client_id' => $client->id]); // call-first rule
        Sanctum::actingAs($this->agent());

        $this->putJson("/api/v1/clients/{$client->id}/desire", ['budget_min' => 5000000, 'budget_max' => 1000000, 'notes' => 'Inverted range on purpose'])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('budget_max');
    }

    public function test_desire_notes_are_required(): void
    {
        $client = Client::factory()->create();
        Call::factory()->create(['client_id' => $client->id]); // call-first rule
        Sanctum::actingAs($this->agent());

        $this->putJson("/api/v1/clients/{$client->id}/desire", ['budget_max' => 5000000])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('notes');
    }

    public function test_desire_captures_the_structured_profile_and_preferred_sites(): void
    {
        $client = Client::factory()->create();
        Call::factory()->create(['client_id' => $client->id]); // call-first rule
        $floors = DynamicListItem::factory()->count(2)->create();
        $rooms = DynamicListItem::factory()->count(2)->create();
        $site = Location::factory()->create();
        Sanctum::actingAs($this->agent());

        // Every selector is multi-valued — "2nd OR 3rd floor, F2 OR F3".
        $this->putJson("/api/v1/clients/{$client->id}/desire", [
            'floor_ids' => $floors->pluck('id')->all(),
            'room_number_ids' => $rooms->pluck('id')->all(),
            'area_min' => 80, 'area_max' => 120,
            'location_ids' => [$site->id],
            'notes' => 'F2/F3, 80-120sqm, prefers this site',
        ])
            ->assertOk()
            ->assertJsonPath('data.floor_ids.0', $floors[0]->id)
            ->assertJsonPath('data.floor_ids.1', $floors[1]->id)
            ->assertJsonPath('data.location_ids.0', $site->id);

        $this->assertDatabaseHas('desire_locations', ['location_id' => $site->id]);
        $this->assertDatabaseHas('desire_list_items', ['item_id' => $floors[0]->id, 'field' => 'floor']);
        $this->assertDatabaseHas('desire_list_items', ['item_id' => $rooms[1]->id, 'field' => 'room_number']);

        // Re-sending a shorter list re-syncs (removals stick).
        $this->putJson("/api/v1/clients/{$client->id}/desire", [
            'floor_ids' => [$floors[0]->id],
            'notes' => 'Second floor only after all',
        ])
            ->assertOk()
            ->assertJsonCount(1, 'data.floor_ids');

        $this->assertDatabaseMissing('desire_list_items', ['item_id' => $floors[1]->id, 'field' => 'floor']);
    }

    public function test_matching_respects_area_floor_and_preferred_sites(): void
    {
        $type = DynamicListItem::factory()->create();
        $floor = DynamicListItem::factory()->create();
        // Project type is a location attribute the units inherit.
        $site = Location::factory()->create(['type_id' => $type->id]);
        $otherSite = Location::factory()->create(['type_id' => $type->id]);

        $match = Unit::factory()->for($site)->create([
            'reference' => 'OK', 'sale_status' => 'available',
            'floor_id' => $floor->id, 'area_sqm' => 100, 'price_semi_fini' => 5000000,
        ]);
        // Wrong floor / too small / wrong site — all excluded.
        Unit::factory()->for($site)->create(['reference' => 'X-floor', 'sale_status' => 'available', 'area_sqm' => 100, 'price_semi_fini' => 5000000]);
        Unit::factory()->for($site)->create(['reference' => 'X-small', 'sale_status' => 'available', 'floor_id' => $floor->id, 'area_sqm' => 50, 'price_semi_fini' => 5000000]);
        Unit::factory()->for($otherSite)->create(['reference' => 'X-site', 'sale_status' => 'available', 'floor_id' => $floor->id, 'area_sqm' => 100, 'price_semi_fini' => 5000000]);

        $client = Client::factory()->create();
        $desire = Desire::factory()->create([
            'client_id' => $client->id, 'area_min' => 80, 'area_max' => 120,
        ]);
        $desire->types()->attach($type->id);
        $desire->floors()->attach($floor->id);
        $desire->locations()->sync([$site->id]);

        Sanctum::actingAs($this->agent());

        $this->getJson("/api/v1/clients/{$client->id}/matches")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $match->id);
    }

    public function test_matching_returns_only_available_units_that_fit(): void
    {
        $wilaya = Wilaya::factory()->create();
        $type = DynamicListItem::factory()->create();
        $otherType = DynamicListItem::factory()->create();

        // Project type is a location attribute — a wrong-type unit lives in a
        // wrong-type project.
        $location = Location::factory()->create(['wilaya_id' => $wilaya->id, 'type_id' => $type->id]);
        $otherLocation = Location::factory()->create(['wilaya_id' => null, 'type_id' => $type->id]);
        $otherTypeLocation = Location::factory()->create(['wilaya_id' => $wilaya->id, 'type_id' => $otherType->id]);

        // The one true match.
        $match = Unit::factory()->for($location)->create([
            'reference' => 'M-1', 'sale_status' => 'available', 'price_semi_fini' => 5000000,
        ]);
        // Excluded for various reasons.
        Unit::factory()->for($location)->create(['reference' => 'X-sold', 'sale_status' => 'sold', 'price_semi_fini' => 5000000]);
        Unit::factory()->for($location)->create(['reference' => 'X-pricey', 'sale_status' => 'available', 'price_semi_fini' => 99000000]);
        Unit::factory()->for($otherTypeLocation)->create(['reference' => 'X-type', 'sale_status' => 'available', 'price_semi_fini' => 5000000]);
        Unit::factory()->for($otherLocation)->create(['reference' => 'X-wilaya', 'sale_status' => 'available', 'price_semi_fini' => 5000000]);

        $client = Client::factory()->create();
        $desire = Desire::factory()->create([
            'client_id' => $client->id,
            'budget_min' => 1000000, 'budget_max' => 10000000,
        ]);
        $desire->wilayas()->attach($wilaya->id);
        $desire->types()->attach($type->id);

        Sanctum::actingAs($this->agent());

        $this->getJson("/api/v1/clients/{$client->id}/matches")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $match->id);
    }

    public function test_multi_valued_criteria_match_any_of_the_picked_values(): void
    {
        $floorA = DynamicListItem::factory()->create();
        $floorB = DynamicListItem::factory()->create();
        $floorC = DynamicListItem::factory()->create();
        $site = Location::factory()->create();

        // One unit per floor — the desire picks floors A OR B, so C is out.
        $onA = Unit::factory()->for($site)->create(['reference' => 'A', 'sale_status' => 'available', 'floor_id' => $floorA->id, 'price_semi_fini' => 5000000]);
        $onB = Unit::factory()->for($site)->create(['reference' => 'B', 'sale_status' => 'available', 'floor_id' => $floorB->id, 'price_semi_fini' => 5000000]);
        Unit::factory()->for($site)->create(['reference' => 'C', 'sale_status' => 'available', 'floor_id' => $floorC->id, 'price_semi_fini' => 5000000]);

        $client = Client::factory()->create();
        $desire = Desire::factory()->create(['client_id' => $client->id]);
        $desire->floors()->attach([$floorA->id, $floorB->id]);

        Sanctum::actingAs($this->agent());

        $response = $this->getJson("/api/v1/clients/{$client->id}/matches")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertEqualsCanonicalizing([$onA->id, $onB->id], $ids->all());
    }

    public function test_matches_are_ranked_by_closeness_to_budget(): void
    {
        $type = DynamicListItem::factory()->create();
        $location = Location::factory()->create(['type_id' => $type->id]);

        // Budget 2M–6M → midpoint 4M. The unit nearest 4M should rank first.
        $near = Unit::factory()->for($location)->create(['reference' => 'N', 'sale_status' => 'available', 'price_semi_fini' => 4100000]);
        Unit::factory()->for($location)->create(['reference' => 'F', 'sale_status' => 'available', 'price_semi_fini' => 5900000]);

        $client = Client::factory()->create();
        Desire::factory()->create([
            'client_id' => $client->id,
            'budget_min' => 2000000, 'budget_max' => 6000000,
        ])->types()->attach($type->id);

        Sanctum::actingAs($this->agent());

        $this->getJson("/api/v1/clients/{$client->id}/matches")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $near->id);
    }

    public function test_every_picked_commune_must_belong_to_a_picked_wilaya(): void
    {
        $client = Client::factory()->create();
        Call::factory()->create(['client_id' => $client->id]); // call-first rule
        $wilaya = Wilaya::factory()->create();
        $other = Wilaya::factory()->create();
        $commune = Commune::factory()->create(['wilaya_id' => $other->id]);
        Sanctum::actingAs($this->agent());

        $this->putJson("/api/v1/clients/{$client->id}/desire", [
            'wilaya_ids' => [$wilaya->id],
            'commune_ids' => [$commune->id],
            'notes' => 'Commune outside the picked wilayas',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('commune_ids');

        $this->putJson("/api/v1/clients/{$client->id}/desire", [
            'wilaya_ids' => [$wilaya->id, $other->id],
            'commune_ids' => [$commune->id],
            'notes' => 'Now its wilaya is picked too',
        ])->assertOk();
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
