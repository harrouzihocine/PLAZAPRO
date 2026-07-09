<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Clients\Enums\ShortlistState;
use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Clients\Models\Desire;
use App\Modules\Clients\Models\ShortlistItem;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Pipeline\Models\Visit;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Units quote up to TWO prices — semi-fini and fini (at least one). The client
 * picks the finish; the choice lives on the shortlist item (proposal) and the
 * deal item (commitment), and budget matching accepts EITHER price fitting.
 */
class UnitFinishingPriceTest extends TestCase
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

    private function inventoryManager(): User
    {
        return $this->userWithPermissions(['units.view', 'units.manage']);
    }

    private function dealAgent(): User
    {
        return $this->userWithPermissions([
            'clients.view', 'visits.conduct', 'deals.direct', 'projects.view_all', 'shortlist.manage',
        ]);
    }

    // ── Creating / correcting ────────────────────────────────────────────

    public function test_a_unit_can_be_created_with_only_a_fini_price(): void
    {
        $location = Location::factory()->create();
        Sanctum::actingAs($this->inventoryManager());

        $this->postJson("/api/v1/locations/{$location->id}/units", [
            'reference' => 'F-1', 'price_fini' => 900000,
        ])
            ->assertCreated()
            ->assertJsonPath('data.price_fini', '900000.00')
            ->assertJsonPath('data.price_semi_fini', null);
    }

    public function test_a_unit_needs_at_least_one_price(): void
    {
        $location = Location::factory()->create();
        Sanctum::actingAs($this->inventoryManager());

        $this->postJson("/api/v1/locations/{$location->id}/units", ['reference' => 'F-2'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['price_semi_fini', 'price_fini']);
    }

    public function test_a_correction_can_add_a_fini_price_as_a_new_version(): void
    {
        $unit = Unit::factory()->create(['price_semi_fini' => '500000.00']);
        Sanctum::actingAs($this->inventoryManager());

        $this->postJson("/api/v1/units/{$unit->id}/correct", [
            'price_fini' => 650000, 'reason' => 'Fini offer added',
        ])
            ->assertOk()
            ->assertJsonPath('data.price_semi_fini', '500000.00')
            ->assertJsonPath('data.price_fini', '650000.00');

        // Versioned like every price correction: old row cancelled, new linked.
        $this->assertSame('cancelled', $unit->fresh()->status->value);
        $this->assertDatabaseHas('units', [
            'supersedes_id' => $unit->id, 'status' => 'active', 'price_fini' => '650000.00',
        ]);
    }

    public function test_a_correction_cannot_remove_the_last_price(): void
    {
        $unit = Unit::factory()->create(['price_semi_fini' => '500000.00', 'price_fini' => null]);
        Sanctum::actingAs($this->inventoryManager());

        $this->postJson("/api/v1/units/{$unit->id}/correct", [
            'price_semi_fini' => null, 'reason' => 'Oops',
        ])->assertStatus(422);

        $this->assertSame('active', $unit->fresh()->status->value);
    }

    // ── Either-price budget matching ─────────────────────────────────────

    public function test_desire_budget_matches_when_only_the_fini_price_fits(): void
    {
        $client = Client::factory()->create();
        Desire::factory()->create([
            'client_id' => $client->id,
            'budget_min' => '600000.00',
            'budget_max' => '700000.00',
        ]);

        // Semi-fini is under the window, fini inside it → the unit matches.
        $unit = Unit::factory()->create([
            'price_semi_fini' => '500000.00', 'price_fini' => '650000.00',
        ]);

        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'units.view']));

        $this->getJson("/api/v1/clients/{$client->id}/matches")
            ->assertOk()
            ->assertJsonPath('data.0.id', $unit->id);
    }

    public function test_budget_window_must_fit_one_price_not_a_bound_each(): void
    {
        $client = Client::factory()->create();
        Desire::factory()->create([
            'client_id' => $client->id,
            'budget_min' => '600000.00',
            'budget_max' => '700000.00',
        ]);

        // Semi-fini below the min AND fini above the max — neither offer fits,
        // even though each bound is satisfied by SOME price.
        Unit::factory()->create([
            'price_semi_fini' => '400000.00', 'price_fini' => '900000.00',
        ]);

        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'units.view']));

        $this->getJson("/api/v1/clients/{$client->id}/matches")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    // ── The choice on shortlist and deal ─────────────────────────────────

    public function test_shortlist_stores_the_proposed_finish_and_rejects_unoffered_ones(): void
    {
        $project = ClientProject::factory()->create();
        $dual = Unit::factory()->dualPrice()->create();
        $semiOnly = Unit::factory()->create();
        Sanctum::actingAs($this->dealAgent());

        $this->putJson("/api/v1/projects/{$project->id}/shortlist", [
            'items' => [
                ['shortlistable_type' => 'unit', 'shortlistable_id' => $dual->id, 'finish_type' => 'fini'],
                ['shortlistable_type' => 'unit', 'shortlistable_id' => $semiOnly->id],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.0.finish_type', 'fini')
            // `price` follows the proposed finish; both raw prices ride along.
            ->assertJsonPath('data.0.property.price', $dual->price_fini)
            ->assertJsonPath('data.1.finish_type', 'semi_fini');

        // A finish the unit does not offer is refused.
        $this->putJson("/api/v1/projects/{$project->id}/shortlist", [
            'items' => [
                ['shortlistable_type' => 'unit', 'shortlistable_id' => $semiOnly->id, 'finish_type' => 'fini'],
            ],
        ])->assertStatus(422);
    }

    public function test_the_deal_carries_the_shortlist_finish_and_can_switch_while_open(): void
    {
        $client = Client::factory()->create();
        $project = ClientProject::factory()->create(['client_id' => $client->id]);
        $unit = Unit::factory()->dualPrice()->create();

        ShortlistItem::factory()->create([
            'client_project_id' => $project->id,
            'shortlistable_id' => $unit->id,
            'state' => ShortlistState::VisitedInterested->value,
            'finish_type' => 'fini',
        ]);
        $visit = Visit::factory()->completed()->create([
            'client_id' => $client->id,
            'client_project_id' => $project->id,
        ]);

        Sanctum::actingAs($this->dealAgent());

        // The deal item inherits the shortlist proposal (fini) and quotes ITS price.
        $deal = $this->postJson("/api/v1/projects/{$project->id}/deals", [
            'visit_id' => $visit->id,
            'units' => [['unit_id' => $unit->id]],
        ])
            ->assertCreated()
            ->assertJsonPath('data.units.0.finish_type', 'fini')
            ->assertJsonPath('data.units.0.price', $unit->price_fini)
            ->json('data');

        // The client changes their mind while the deal is open → semi-fini.
        $this->patchJson("/api/v1/deals/{$deal['id']}/items/{$deal['units'][0]['item_id']}/finish", [
            'finish_type' => 'semi_fini',
        ])
            ->assertOk()
            ->assertJsonPath('data.units.0.finish_type', 'semi_fini')
            ->assertJsonPath('data.units.0.price', $unit->price_semi_fini);

        // The shortlist proposal mirrors the commitment.
        $this->assertDatabaseHas('shortlist_items', [
            'client_project_id' => $project->id,
            'shortlistable_id' => $unit->id,
            'finish_type' => 'semi_fini',
        ]);
    }

    public function test_an_explicit_deal_finish_must_be_offered_by_the_unit(): void
    {
        $client = Client::factory()->create();
        $project = ClientProject::factory()->create(['client_id' => $client->id]);
        $semiOnly = Unit::factory()->create();
        $visit = Visit::factory()->completed()->create([
            'client_id' => $client->id,
            'client_project_id' => $project->id,
        ]);

        Sanctum::actingAs($this->dealAgent());

        $this->postJson("/api/v1/projects/{$project->id}/deals", [
            'visit_id' => $visit->id,
            'units' => [['unit_id' => $semiOnly->id, 'finish_type' => 'fini']],
        ])->assertStatus(422);
    }
}
