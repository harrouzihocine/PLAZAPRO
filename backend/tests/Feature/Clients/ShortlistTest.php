<?php

declare(strict_types=1);

namespace Tests\Feature\Clients;

use App\Modules\Clients\Models\ClientProject;
use App\Modules\Clients\Models\ShortlistItem;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShortlistTest extends TestCase
{
    use RefreshDatabase;

    /** @param  list<string>  $slugs */
    private function userWith(array $slugs): User
    {
        $role = Role::factory()->create();
        $role->permissions()->sync(collect($slugs)->map(
            fn (string $slug) => Permission::firstOrCreate(['slug' => $slug], ['name' => $slug])->id,
        ));

        return User::factory()->create(['role_id' => $role->id]);
    }

    public function test_a_shortlist_requires_at_least_one_property(): void
    {
        $project = ClientProject::factory()->create();
        Sanctum::actingAs($this->userWith(['clients.view', 'visits.conduct']));

        $this->putJson("/api/v1/projects/{$project->id}/shortlist", ['items' => []])
            ->assertStatus(422)->assertJsonValidationErrorFor('items');
    }

    public function test_syncing_adds_removes_and_preserves_state(): void
    {
        $project = ClientProject::factory()->create();
        $unitA = Unit::factory()->create();
        $unitB = Unit::factory()->create();
        Sanctum::actingAs($this->userWith(['clients.view', 'visits.conduct']));

        $this->putJson("/api/v1/projects/{$project->id}/shortlist", [
            'items' => [['shortlistable_type' => 'unit', 'shortlistable_id' => $unitA->id]],
        ])->assertOk()->assertJsonCount(1, 'data');

        // The site agent later marks unit A visited/interested.
        ShortlistItem::query()->where('shortlistable_id', $unitA->id)->update(['state' => 'visited_interested']);

        // Re-sync keeping A and adding B — A must keep its journey state (not reset).
        $this->putJson("/api/v1/projects/{$project->id}/shortlist", [
            'items' => [
                ['shortlistable_type' => 'unit', 'shortlistable_id' => $unitA->id],
                ['shortlistable_type' => 'unit', 'shortlistable_id' => $unitB->id],
            ],
        ])->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame('visited_interested', ShortlistItem::query()->active()
            ->where('shortlistable_id', $unitA->id)->value('state')->value);

        // Dropping A from the list cancels it (kept in history, not deleted).
        $this->putJson("/api/v1/projects/{$project->id}/shortlist", [
            'items' => [['shortlistable_type' => 'unit', 'shortlistable_id' => $unitB->id]],
        ])->assertOk()->assertJsonCount(1, 'data');
        $this->assertDatabaseHas('shortlist_items', ['shortlistable_id' => $unitA->id, 'status' => 'cancelled']);
    }

    public function test_winning_an_interested_property_closes_the_deal(): void
    {
        $project = ClientProject::factory()->create();
        $unit = Unit::factory()->create();
        $item = ShortlistItem::factory()->create([
            'client_project_id' => $project->id, 'shortlistable_type' => 'unit',
            'shortlistable_id' => $unit->id, 'state' => 'visited_interested',
        ]);
        Sanctum::actingAs($this->userWith(['clients.view', 'clients.manage']));

        $this->postJson("/api/v1/shortlist-items/{$item->id}/outcome", ['outcome' => 'won', 'total_price' => '2500000.00'])
            ->assertOk()->assertJsonPath('data.state', 'won');

        $this->assertDatabaseHas('client_projects', [
            'id' => $project->id, 'unit_id' => $unit->id, 'stage' => 'won', 'total_price' => '2500000.00',
        ]);
        $this->assertDatabaseHas('units', ['id' => $unit->id, 'sale_status' => 'sold']);
    }

    public function test_only_a_visited_interested_property_can_be_closed(): void
    {
        $item = ShortlistItem::factory()->create(['state' => 'shortlisted']);
        Sanctum::actingAs($this->userWith(['clients.view', 'clients.manage']));

        $this->postJson("/api/v1/shortlist-items/{$item->id}/outcome", ['outcome' => 'lost'])
            ->assertStatus(422);
    }
}
