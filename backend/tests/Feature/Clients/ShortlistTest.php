<?php

declare(strict_types=1);

namespace Tests\Feature\Clients;

use App\Modules\Clients\Models\ClientProject;
use App\Modules\Clients\Models\Deal;
use App\Modules\Clients\Models\DealItem;
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
        Sanctum::actingAs($this->userWith(['clients.view', 'shortlist.manage']));

        $this->putJson("/api/v1/projects/{$project->id}/shortlist", ['items' => []])
            ->assertStatus(422)->assertJsonValidationErrorFor('items');
    }

    public function test_curating_the_shortlist_requires_shortlist_manage(): void
    {
        // A conducting agent (site / sales) WITHOUT shortlist.manage cannot curate
        // the standalone shortlist — they add properties via "Add unit to visit".
        $project = ClientProject::factory()->create();
        $unit = Unit::factory()->create();
        Sanctum::actingAs($this->userWith(['clients.view', 'visits.conduct']));

        $this->putJson("/api/v1/projects/{$project->id}/shortlist", [
            'items' => [['shortlistable_type' => 'unit', 'shortlistable_id' => $unit->id]],
        ])->assertForbidden();
    }

    public function test_syncing_adds_removes_and_preserves_state(): void
    {
        $project = ClientProject::factory()->create();
        $unitA = Unit::factory()->create();
        $unitB = Unit::factory()->create();
        Sanctum::actingAs($this->userWith(['clients.view', 'shortlist.manage']));

        $this->putJson("/api/v1/projects/{$project->id}/shortlist", [
            'items' => [['shortlistable_type' => 'unit', 'shortlistable_id' => $unitA->id]],
        ])->assertOk()->assertJsonCount(1, 'data');

        // Unit A is later marked visited/interested (e.g. from an in-site visit).
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

    public function test_cannot_remove_a_unit_reserved_on_an_open_deal(): void
    {
        $project = ClientProject::factory()->create();
        $unit = Unit::factory()->create();
        $otherUnit = Unit::factory()->create();
        ShortlistItem::factory()->create([
            'client_project_id' => $project->id, 'shortlistable_type' => 'unit',
            'shortlistable_id' => $unit->id, 'state' => 'shortlisted',
        ]);
        ShortlistItem::factory()->create([
            'client_project_id' => $project->id, 'shortlistable_type' => 'unit',
            'shortlistable_id' => $otherUnit->id, 'state' => 'shortlisted',
        ]);
        $deal = Deal::factory()->create(['client_project_id' => $project->id]);
        DealItem::factory()->create(['deal_id' => $deal->id, 'unit_id' => $unit->id, 'state' => 'reserved']);
        Sanctum::actingAs($this->userWith(['clients.view', 'shortlist.manage']));

        // Payload keeps `otherUnit` but drops the reserved `unit` — must be rejected.
        $this->putJson("/api/v1/projects/{$project->id}/shortlist", [
            'items' => [['shortlistable_type' => 'unit', 'shortlistable_id' => $otherUnit->id]],
        ])->assertStatus(422);
        $this->assertDatabaseHas('shortlist_items', ['shortlistable_id' => $unit->id, 'status' => 'active']);
    }

    public function test_cannot_remove_a_unit_won_on_a_deal(): void
    {
        $project = ClientProject::factory()->create();
        $unit = Unit::factory()->create();
        $otherUnit = Unit::factory()->create();
        ShortlistItem::factory()->create([
            'client_project_id' => $project->id, 'shortlistable_type' => 'unit',
            'shortlistable_id' => $unit->id, 'state' => 'shortlisted',
        ]);
        ShortlistItem::factory()->create([
            'client_project_id' => $project->id, 'shortlistable_type' => 'unit',
            'shortlistable_id' => $otherUnit->id, 'state' => 'shortlisted',
        ]);
        $deal = Deal::factory()->create(['client_project_id' => $project->id, 'state' => 'won']);
        DealItem::factory()->create(['deal_id' => $deal->id, 'unit_id' => $unit->id, 'state' => 'won']);
        Sanctum::actingAs($this->userWith(['clients.view', 'shortlist.manage']));

        $this->putJson("/api/v1/projects/{$project->id}/shortlist", [
            'items' => [['shortlistable_type' => 'unit', 'shortlistable_id' => $otherUnit->id]],
        ])->assertStatus(422);
    }

    public function test_can_remove_a_unit_once_its_deal_is_lost(): void
    {
        $project = ClientProject::factory()->create();
        $unit = Unit::factory()->create();
        $keptUnit = Unit::factory()->create();
        ShortlistItem::factory()->create([
            'client_project_id' => $project->id, 'shortlistable_type' => 'unit',
            'shortlistable_id' => $unit->id, 'state' => 'shortlisted',
        ]);
        ShortlistItem::factory()->create([
            'client_project_id' => $project->id, 'shortlistable_type' => 'unit',
            'shortlistable_id' => $keptUnit->id, 'state' => 'shortlisted',
        ]);
        $deal = Deal::factory()->create(['client_project_id' => $project->id, 'state' => 'lost']);
        DealItem::factory()->create(['deal_id' => $deal->id, 'unit_id' => $unit->id, 'state' => 'lost']);
        Sanctum::actingAs($this->userWith(['clients.view', 'shortlist.manage']));

        $this->putJson("/api/v1/projects/{$project->id}/shortlist", [
            'items' => [['shortlistable_type' => 'unit', 'shortlistable_id' => $keptUnit->id]],
        ])->assertOk();
        $this->assertDatabaseHas('shortlist_items', ['shortlistable_id' => $unit->id, 'status' => 'cancelled']);
    }

    public function test_cannot_remove_a_unit_on_hold_for_this_project(): void
    {
        $project = ClientProject::factory()->create();
        $unit = Unit::factory()->create(['sale_status' => 'onhold', 'onhold_project_id' => $project->id]);
        $otherUnit = Unit::factory()->create();
        ShortlistItem::factory()->create([
            'client_project_id' => $project->id, 'shortlistable_type' => 'unit',
            'shortlistable_id' => $unit->id, 'state' => 'shortlisted',
        ]);
        ShortlistItem::factory()->create([
            'client_project_id' => $project->id, 'shortlistable_type' => 'unit',
            'shortlistable_id' => $otherUnit->id, 'state' => 'shortlisted',
        ]);
        Sanctum::actingAs($this->userWith(['clients.view', 'shortlist.manage']));

        $this->putJson("/api/v1/projects/{$project->id}/shortlist", [
            'items' => [['shortlistable_type' => 'unit', 'shortlistable_id' => $otherUnit->id]],
        ])->assertStatus(422);
    }

    public function test_shortlist_index_exposes_locked_reason(): void
    {
        $project = ClientProject::factory()->create();
        $unit = Unit::factory()->create();
        ShortlistItem::factory()->create([
            'client_project_id' => $project->id, 'shortlistable_type' => 'unit',
            'shortlistable_id' => $unit->id, 'state' => 'shortlisted',
        ]);
        $deal = Deal::factory()->create(['client_project_id' => $project->id]);
        DealItem::factory()->create(['deal_id' => $deal->id, 'unit_id' => $unit->id, 'state' => 'reserved']);
        Sanctum::actingAs($this->userWith(['clients.view', 'projects.view_all']));

        $this->getJson("/api/v1/projects/{$project->id}/shortlist")
            ->assertOk()->assertJsonPath('data.0.locked_reason', 'reserved');
    }
}
