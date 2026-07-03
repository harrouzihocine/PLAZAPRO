<?php

declare(strict_types=1);

namespace Tests\Feature\Clients;

use App\Modules\Clients\Enums\ShortlistState;
use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Clients\Models\Deal;
use App\Modules\Clients\Models\ShortlistItem;
use App\Modules\Inventory\Models\Box;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Pipeline\Models\Visit;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DealTest extends TestCase
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
        return $this->userWithPermissions(['clients.view', 'visits.conduct']);
    }

    private function manager(): User
    {
        return $this->userWithPermissions([
            'clients.view', 'clients.manage', 'visits.conduct', 'deals.direct',
        ]);
    }

    /**
     * A project with a completed office visit and one shortlisted, available unit
     * (plus $boxes available boxes in the same location).
     *
     * @return array{0: ClientProject, 1: Visit, 2: Unit}
     */
    private function projectWithVisitAndUnit(int $boxes = 0): array
    {
        $client = Client::factory()->create();
        $project = ClientProject::factory()->create(['client_id' => $client->id]);
        $location = Location::factory()->create();
        $unit = Unit::factory()->create(['location_id' => $location->id, 'price' => '500000.00']);
        Box::factory()->count($boxes)->create(['location_id' => $location->id, 'price' => '20000.00']);

        ShortlistItem::factory()->create([
            'client_project_id' => $project->id,
            'shortlistable_id' => $unit->id,
            'state' => ShortlistState::VisitedInterested->value,
        ]);

        $visit = Visit::factory()->completed()->create([
            'client_id' => $client->id,
            'client_project_id' => $project->id,
        ]);

        return [$project, $visit, $unit];
    }

    public function test_a_deal_from_a_visit_reserves_the_selected_properties(): void
    {
        [$project, $visit, $unit] = $this->projectWithVisitAndUnit(boxes: 3);
        Sanctum::actingAs($this->agent());

        $this->postJson("/api/v1/projects/{$project->id}/deals", [
            'visit_id' => $visit->id,
            'units' => [['unit_id' => $unit->id, 'box_count' => 2]],
        ])
            ->assertCreated()
            ->assertJsonPath('data.state', 'reserved')
            ->assertJsonPath('data.units.0.reference', $unit->reference)
            ->assertJsonCount(2, 'data.boxes');

        // The unit is auto-reserved with a 48h hold on this project.
        $this->assertSame('reserved', $unit->fresh()->sale_status->value);
        $this->assertDatabaseHas('reservations', [
            'unit_id' => $unit->id, 'client_project_id' => $project->id, 'hold_status' => 'active',
        ]);

        // Two of the three boxes are reserved alongside.
        $this->assertSame(2, Box::query()->where('sale_status', 'reserved')->count());

        // The project sits at the reserved step.
        $this->assertSame('reserved', $project->fresh()->stage->value);
    }

    public function test_only_one_active_deal_per_project(): void
    {
        [$project, $visit, $unit] = $this->projectWithVisitAndUnit();
        Sanctum::actingAs($this->agent());

        $payload = ['visit_id' => $visit->id, 'units' => [['unit_id' => $unit->id]]];
        $this->postJson("/api/v1/projects/{$project->id}/deals", $payload)->assertCreated();
        $this->postJson("/api/v1/projects/{$project->id}/deals", $payload)->assertStatus(422);
    }

    public function test_a_direct_deal_requires_the_permission(): void
    {
        [$project, , $unit] = $this->projectWithVisitAndUnit();

        // No visit provenance + no deals.direct → refused.
        Sanctum::actingAs($this->agent());
        $this->postJson("/api/v1/projects/{$project->id}/deals", [
            'units' => [['unit_id' => $unit->id]],
        ])->assertForbidden();

        // A manager (deals.direct) may open one directly.
        Sanctum::actingAs($this->manager());
        $this->postJson("/api/v1/projects/{$project->id}/deals", [
            'units' => [['unit_id' => $unit->id]],
        ])->assertCreated();
    }

    public function test_the_visit_must_belong_to_the_project(): void
    {
        [$project, , $unit] = $this->projectWithVisitAndUnit();
        $foreignVisit = Visit::factory()->create(); // another client's visit
        Sanctum::actingAs($this->agent());

        $this->postJson("/api/v1/projects/{$project->id}/deals", [
            'visit_id' => $foreignVisit->id,
            'units' => [['unit_id' => $unit->id]],
        ])->assertStatus(422);
    }

    public function test_a_rejected_property_cannot_enter_the_deal(): void
    {
        [$project, $visit, $unit] = $this->projectWithVisitAndUnit();
        $project->shortlistItems()->first()->update([
            'state' => ShortlistState::VisitedNotInterested->value,
        ]);
        Sanctum::actingAs($this->agent());

        $this->postJson("/api/v1/projects/{$project->id}/deals", [
            'visit_id' => $visit->id,
            'units' => [['unit_id' => $unit->id]],
        ])->assertStatus(422);
    }

    public function test_closing_won_sells_everything_and_advances_the_project(): void
    {
        [$project, $visit, $unit] = $this->projectWithVisitAndUnit(boxes: 1);
        Sanctum::actingAs($this->manager());

        $dealId = $this->postJson("/api/v1/projects/{$project->id}/deals", [
            'visit_id' => $visit->id,
            'units' => [['unit_id' => $unit->id, 'box_count' => 1]],
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/deals/{$dealId}/close", [
            'outcome' => 'won', 'total_price' => '520000.00',
        ])
            ->assertOk()
            ->assertJsonPath('data.state', 'won')
            ->assertJsonPath('data.total_price', '520000.00');

        $project->refresh();
        $this->assertSame('won', $project->stage->value);
        $this->assertSame($unit->id, $project->unit_id);
        $this->assertSame('520000.00', $project->total_price);

        $this->assertSame('sold', $unit->fresh()->sale_status->value);
        $this->assertSame(1, Box::query()->where('sale_status', 'sold')->count());
        $this->assertDatabaseHas('reservations', ['unit_id' => $unit->id, 'hold_status' => 'converted']);
        $this->assertDatabaseHas('shortlist_items', ['shortlistable_id' => $unit->id, 'state' => 'won']);
    }

    public function test_closing_lost_releases_everything(): void
    {
        [$project, $visit, $unit] = $this->projectWithVisitAndUnit(boxes: 1);
        Sanctum::actingAs($this->manager());

        $dealId = $this->postJson("/api/v1/projects/{$project->id}/deals", [
            'visit_id' => $visit->id,
            'units' => [['unit_id' => $unit->id, 'box_count' => 1]],
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/deals/{$dealId}/close", ['outcome' => 'lost'])
            ->assertOk()
            ->assertJsonPath('data.state', 'lost');

        $this->assertSame('available', $unit->fresh()->sale_status->value);
        $this->assertSame(0, Box::query()->where('sale_status', 'reserved')->count());
        $this->assertDatabaseHas('reservations', ['unit_id' => $unit->id, 'hold_status' => 'released']);
        // The project steps back into play.
        $this->assertSame('negotiating', $project->fresh()->stage->value);
    }

    public function test_the_reserved_boxes_can_be_changed_while_the_deal_is_open(): void
    {
        [$project, $visit, $unit] = $this->projectWithVisitAndUnit(boxes: 2);
        Sanctum::actingAs($this->agent());

        $deal = $this->postJson("/api/v1/projects/{$project->id}/deals", [
            'visit_id' => $visit->id,
            'units' => [['unit_id' => $unit->id, 'box_count' => 1]],
        ])->assertCreated()->json('data');

        $reservedBoxId = $deal['boxes'][0]['id'];
        $otherBox = Box::query()->where('id', '!=', $reservedBoxId)->first();

        // Swap the reserved box for the other one.
        $this->putJson('/api/v1/deals/'.$deal['id'].'/boxes', ['box_ids' => [$otherBox->id]])
            ->assertOk()
            ->assertJsonCount(1, 'data.boxes')
            ->assertJsonPath('data.boxes.0.id', $otherBox->id);

        $this->assertSame('available', Box::find($reservedBoxId)->sale_status->value);
        $this->assertSame('reserved', $otherBox->fresh()->sale_status->value);
    }

    public function test_a_closed_deal_shows_in_the_project_deal_list(): void
    {
        $deal = Deal::factory()->create();
        Sanctum::actingAs($this->userWithPermissions(['clients.view']));

        $this->getJson("/api/v1/projects/{$deal->client_project_id}/deals")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $deal->id);
    }
}
