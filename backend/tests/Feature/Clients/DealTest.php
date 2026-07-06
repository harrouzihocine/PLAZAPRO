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
use App\Modules\Payments\Models\Versement;
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
            'clients.view', 'deals.manage', 'visits.conduct', 'deals.direct',
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
        $picked = Box::query()->orderBy('id')->limit(2)->get();
        Sanctum::actingAs($this->agent());

        $this->postJson("/api/v1/projects/{$project->id}/deals", [
            'visit_id' => $visit->id,
            'units' => [['unit_id' => $unit->id, 'box_ids' => $picked->pluck('id')->all()]],
        ])
            ->assertCreated()
            ->assertJsonPath('data.state', 'reserved')
            ->assertJsonPath('data.units.0.state', 'reserved')
            ->assertJsonPath('data.units.0.reference', $unit->reference)
            ->assertJsonCount(2, 'data.boxes');

        // The unit is reserved on this project with a NO-EXPIRY hold — only
        // closing the deal releases or converts it.
        $this->assertSame('reserved', $unit->fresh()->sale_status->value);
        $this->assertDatabaseHas('reservations', [
            'unit_id' => $unit->id, 'client_project_id' => $project->id,
            'hold_status' => 'active', 'expires_at' => null,
        ]);

        // The two picked boxes are reserved AND linked to the apartment.
        foreach ($picked as $box) {
            $this->assertSame('reserved', $box->fresh()->sale_status->value);
            $this->assertSame($unit->id, $box->fresh()->unit_id);
        }
        $this->assertSame(2, Box::query()->where('sale_status', 'reserved')->count());

        // The project sits at the reserved step.
        $this->assertSame('reserved', $project->fresh()->stage->value);
    }

    public function test_a_deal_on_the_first_ever_call_ensures_the_project(): void
    {
        // A brand-new client with NO project: the very first call concludes into
        // a deal — the project is created on the fly and the call rides on it
        // (the call is the deal's provenance).
        $client = Client::factory()->create();
        $unit = Unit::factory()->create();
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'calls.log']));

        $this->postJson("/api/v1/clients/{$client->id}/calls", [
            'direction' => 'inbound',
            'closure' => ['type' => 'deal', 'units' => [['unit_id' => $unit->id]]],
        ])->assertCreated();

        $project = $client->projects()->firstOrFail();
        $deal = $project->deals()->firstOrFail();
        $this->assertNotNull($deal->call_id);
        $this->assertSame($project->id, (int) $deal->call->client_project_id);
    }

    public function test_a_box_linked_to_another_apartment_is_refused(): void
    {
        [$project, $visit, $unit] = $this->projectWithVisitAndUnit(boxes: 1);
        $other = Unit::factory()->create(['location_id' => $unit->location_id]);
        $box = Box::query()->firstOrFail();
        $box->update(['unit_id' => $other->id]); // belongs to another apartment
        Sanctum::actingAs($this->agent());

        $this->postJson("/api/v1/projects/{$project->id}/deals", [
            'visit_id' => $visit->id,
            'units' => [['unit_id' => $unit->id, 'box_ids' => [$box->id]]],
        ])->assertStatus(422);
    }

    public function test_a_deal_can_be_born_from_a_call_log_and_the_logs_stay_open(): void
    {
        [$project, , $unit] = $this->projectWithVisitAndUnit();
        $caller = $this->userWithPermissions(['clients.view', 'calls.log']);
        Sanctum::actingAs($caller);

        // The call concludes into a deal — no deals.direct needed, the call IS
        // the provenance. The unit was not shortlisted? It is here — this one
        // is, via projectWithVisitAndUnit.
        $this->postJson("/api/v1/clients/{$project->client_id}/calls", [
            'client_project_id' => $project->id,
            'direction' => 'outbound',
            'closure' => ['type' => 'deal', 'units' => [['unit_id' => $unit->id]]],
        ])->assertCreated();

        $deal = $project->deals()->latest('id')->firstOrFail();
        $this->assertNotNull($deal->call_id);
        $this->assertSame('reserved', $deal->state->value);

        // The log workflow stays OPEN while the deal is open — the client may
        // keep hunting more apartments (one deal per committed apartment).
        $this->postJson("/api/v1/clients/{$project->client_id}/calls", [
            'client_project_id' => $project->id,
            'direction' => 'outbound',
            'next_action' => ['type' => 'call', 'due_date' => now()->addDay()->toDateString()],
        ])->assertCreated();
    }

    public function test_a_project_may_carry_several_open_deals_but_a_unit_reserves_once(): void
    {
        [$project, $visit, $unit] = $this->projectWithVisitAndUnit();
        Sanctum::actingAs($this->agent());

        $payload = ['visit_id' => $visit->id, 'units' => [['unit_id' => $unit->id]]];
        $this->postJson("/api/v1/projects/{$project->id}/deals", $payload)->assertCreated();

        // The same unit cannot be reserved twice — the hold refuses it.
        $this->postJson("/api/v1/projects/{$project->id}/deals", $payload)->assertStatus(422);

        // But ANOTHER apartment opens its own deal alongside the first one —
        // one deal per committed apartment.
        $other = Unit::factory()->create([
            'location_id' => $unit->location_id, 'price' => '400000.00',
        ]);
        $this->postJson("/api/v1/projects/{$project->id}/deals", [
            'visit_id' => $visit->id,
            'units' => [['unit_id' => $other->id]],
        ])->assertCreated();

        $this->assertSame(2, $project->deals()->active()->where('state', 'reserved')->count());
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
        $box = Box::query()->firstOrFail();
        Sanctum::actingAs($this->manager());

        $deal = $this->postJson("/api/v1/projects/{$project->id}/deals", [
            'visit_id' => $visit->id,
            'units' => [['unit_id' => $unit->id, 'box_ids' => [$box->id]]],
        ])->assertCreated()->json('data');

        $this->postJson("/api/v1/deals/{$deal['id']}/close", [
            'outcome' => 'won',
            'items' => [['item_id' => $deal['units'][0]['item_id'], 'agreed_price' => '520000.00']],
        ])
            ->assertOk()
            ->assertJsonPath('data.state', 'won')
            ->assertJsonPath('data.total_price', '520000.00')
            ->assertJsonPath('data.units.0.state', 'won')
            ->assertJsonPath('data.units.0.agreed_price', '520000.00');

        $project->refresh();
        $this->assertSame('won', $project->stage->value);
        $this->assertSame($unit->id, $project->unit_id);
        $this->assertSame('520000.00', $project->total_price);

        $this->assertSame('sold', $unit->fresh()->sale_status->value);
        $this->assertSame(1, Box::query()->where('sale_status', 'sold')->count());
        // The won box keeps its link to the sold apartment.
        $this->assertSame($unit->id, $box->fresh()->unit_id);
        $this->assertDatabaseHas('reservations', ['unit_id' => $unit->id, 'hold_status' => 'converted']);
        $this->assertDatabaseHas('shortlist_items', ['shortlistable_id' => $unit->id, 'state' => 'won']);
    }

    public function test_each_apartment_closes_on_its_own(): void
    {
        // Two shortlisted apartments enter the deal; the client buys one and
        // passes on the other — the deal resolves itself as won.
        [$project, $visit, $unit] = $this->projectWithVisitAndUnit();
        $second = Unit::factory()->create(['location_id' => $unit->location_id, 'price' => '400000.00']);
        ShortlistItem::factory()->create([
            'client_project_id' => $project->id,
            'shortlistable_id' => $second->id,
            'state' => ShortlistState::VisitedInterested->value,
        ]);
        Sanctum::actingAs($this->manager());

        $deal = $this->postJson("/api/v1/projects/{$project->id}/deals", [
            'visit_id' => $visit->id,
            'units' => [['unit_id' => $unit->id], ['unit_id' => $second->id]],
        ])->assertCreated()->json('data');

        [$firstItem, $secondItem] = [$deal['units'][0]['item_id'], $deal['units'][1]['item_id']];

        // Winning ONE apartment leaves the deal open for the other.
        $this->postJson("/api/v1/deals/{$deal['id']}/items/{$firstItem}/close", [
            'outcome' => 'won', 'agreed_price' => '510000.00',
        ])
            ->assertOk()
            ->assertJsonPath('data.state', 'reserved')
            ->assertJsonPath('data.units.0.state', 'won');

        $this->assertSame('sold', $unit->fresh()->sale_status->value);
        $this->assertSame('reserved', $second->fresh()->sale_status->value);

        // Losing the second resolves the whole deal as WON (one apartment sold).
        $this->postJson("/api/v1/deals/{$deal['id']}/items/{$secondItem}/close", ['outcome' => 'lost'])
            ->assertOk()
            ->assertJsonPath('data.state', 'won')
            ->assertJsonPath('data.total_price', '510000.00');

        $this->assertSame('available', $second->fresh()->sale_status->value);
        $this->assertDatabaseHas('reservations', ['unit_id' => $second->id, 'hold_status' => 'released']);

        $project->refresh();
        $this->assertSame('won', $project->stage->value);
        $this->assertSame('510000.00', $project->total_price);
        // Per-apartment payments reconcile to the apartment's own price.
        $this->assertSame('510000.00', $project->agreedPriceForUnit($unit->id));
        $this->assertNull($project->agreedPriceForUnit($second->id));
    }

    public function test_losing_an_apartment_unlinks_the_box_this_deal_linked(): void
    {
        [$project, $visit, $unit] = $this->projectWithVisitAndUnit(boxes: 1);
        $box = Box::query()->firstOrFail(); // unlinked before the deal
        Sanctum::actingAs($this->manager());

        $deal = $this->postJson("/api/v1/projects/{$project->id}/deals", [
            'visit_id' => $visit->id,
            'units' => [['unit_id' => $unit->id, 'box_ids' => [$box->id]]],
        ])->assertCreated()->json('data');

        $this->assertSame($unit->id, $box->fresh()->unit_id); // linked by the deal

        $this->postJson("/api/v1/deals/{$deal['id']}/close", ['outcome' => 'lost', 'resolution' => 'reopen'])
            ->assertOk()
            ->assertJsonPath('data.state', 'lost');

        // Released AND unlinked again — the link belonged to the dead deal.
        $this->assertSame('available', $box->fresh()->sale_status->value);
        $this->assertNull($box->fresh()->unit_id);
    }

    public function test_closing_lost_releases_everything(): void
    {
        [$project, $visit, $unit] = $this->projectWithVisitAndUnit(boxes: 1);
        Sanctum::actingAs($this->manager());

        $dealId = $this->postJson("/api/v1/projects/{$project->id}/deals", [
            'visit_id' => $visit->id,
            'units' => [['unit_id' => $unit->id, 'box_count' => 1]],
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/deals/{$dealId}/close", ['outcome' => 'lost', 'resolution' => 'reopen'])
            ->assertOk()
            ->assertJsonPath('data.state', 'lost');

        $this->assertSame('available', $unit->fresh()->sale_status->value);
        $this->assertSame(0, Box::query()->where('sale_status', 'reserved')->count());
        $this->assertDatabaseHas('reservations', ['unit_id' => $unit->id, 'hold_status' => 'released']);
        // The project steps back into play.
        $this->assertSame('negotiating', $project->fresh()->stage->value);
    }

    public function test_losing_a_deal_without_a_resolution_defaults_to_reopen(): void
    {
        // The resolution only matters when nothing else carries the project;
        // omitted, the pipeline reopens (negotiating) so nothing dead-ends.
        [$project, $visit, $unit] = $this->projectWithVisitAndUnit();
        Sanctum::actingAs($this->manager());

        $dealId = $this->postJson("/api/v1/projects/{$project->id}/deals", [
            'visit_id' => $visit->id,
            'units' => [['unit_id' => $unit->id]],
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/deals/{$dealId}/close", ['outcome' => 'lost'])
            ->assertOk()
            ->assertJsonPath('data.state', 'lost');

        $this->assertSame('negotiating', $project->fresh()->stage->value);
        $this->assertSame('available', $unit->fresh()->sale_status->value);
    }

    public function test_losing_a_deal_with_payments_can_archive_keeping_them_as_history(): void
    {
        [$project, $visit, $unit] = $this->projectWithVisitAndUnit();
        Sanctum::actingAs($this->manager());

        $dealId = $this->postJson("/api/v1/projects/{$project->id}/deals", [
            'visit_id' => $visit->id,
            'units' => [['unit_id' => $unit->id]],
        ])->assertCreated()->json('data.id');

        // A deposit was already recorded on the project.
        Versement::factory()->create(['client_project_id' => $project->id, 'amount' => '100000.00']);

        // Losing + archiving is allowed even with payments — they are kept as history.
        $this->postJson("/api/v1/deals/{$dealId}/close", [
            'outcome' => 'lost', 'resolution' => 'archive', 'note' => 'Refund pending',
        ])->assertOk()->assertJsonPath('data.state', 'lost');

        $this->assertSame('archived', $project->fresh()->status->value);
        $this->assertTrue($project->fresh()->versements()->active()->exists());
        $this->assertStringContainsString('Refund pending', (string) $project->fresh()->cancellation_reason);
    }

    public function test_a_won_apartment_can_be_released_and_the_project_reopens(): void
    {
        // The sale fell through AFTER the win: releasing the won apartment puts
        // it (and its boxes) back on the market, un-wins the project (reopen)
        // and keeps the recorded payments as refundable history.
        [$project, $visit, $unit] = $this->projectWithVisitAndUnit(boxes: 1);
        $box = Box::query()->firstOrFail();
        Sanctum::actingAs($this->manager());

        $deal = $this->postJson("/api/v1/projects/{$project->id}/deals", [
            'visit_id' => $visit->id,
            'units' => [['unit_id' => $unit->id, 'box_ids' => [$box->id]]],
        ])->assertCreated()->json('data');

        $this->postJson("/api/v1/deals/{$deal['id']}/close", [
            'outcome' => 'won',
            'items' => [['item_id' => $deal['units'][0]['item_id'], 'agreed_price' => '520000.00']],
        ])->assertOk();

        Versement::factory()->create(['client_project_id' => $project->id, 'amount' => '100000.00']);

        $this->postJson("/api/v1/deals/{$deal['id']}/items/{$deal['units'][0]['item_id']}/release")
            ->assertOk()
            ->assertJsonPath('data.state', 'lost')
            ->assertJsonPath('data.units.0.state', 'lost');

        // Everything returns to the market; the box this deal linked unlinks.
        $this->assertSame('available', $unit->fresh()->sale_status->value);
        $this->assertSame('available', $box->fresh()->sale_status->value);
        $this->assertNull($box->fresh()->unit_id);
        $this->assertDatabaseHas('reservations', ['unit_id' => $unit->id, 'hold_status' => 'released']);

        // The project un-wins and steps back into the pipeline; the payments stay.
        $project->refresh();
        $this->assertSame('negotiating', $project->stage->value);
        $this->assertNull($project->unit_id);
        $this->assertNull($project->total_price);
        $this->assertTrue($project->versements()->active()->exists());
    }

    public function test_releasing_the_won_apartment_can_archive_keeping_payments(): void
    {
        [$project, $visit, $unit] = $this->projectWithVisitAndUnit();
        Sanctum::actingAs($this->manager());

        $deal = $this->postJson("/api/v1/projects/{$project->id}/deals", [
            'visit_id' => $visit->id,
            'units' => [['unit_id' => $unit->id]],
        ])->assertCreated()->json('data');

        $this->postJson("/api/v1/deals/{$deal['id']}/close", [
            'outcome' => 'won',
            'items' => [['item_id' => $deal['units'][0]['item_id'], 'agreed_price' => '500000.00']],
        ])->assertOk();

        Versement::factory()->create(['client_project_id' => $project->id, 'amount' => '50000.00']);

        $this->postJson("/api/v1/deals/{$deal['id']}/items/{$deal['units'][0]['item_id']}/release", [
            'resolution' => 'archive', 'note' => 'Bank financing refused',
        ])->assertOk();

        $project->refresh();
        $this->assertSame('archived', $project->status->value);
        $this->assertTrue($project->versements()->active()->exists());
        $this->assertStringContainsString('Bank financing refused', (string) $project->cancellation_reason);
        $this->assertSame('available', $unit->fresh()->sale_status->value);
    }

    public function test_only_a_won_apartment_can_be_released(): void
    {
        [$project, $visit, $unit] = $this->projectWithVisitAndUnit();
        Sanctum::actingAs($this->manager());

        $deal = $this->postJson("/api/v1/projects/{$project->id}/deals", [
            'visit_id' => $visit->id,
            'units' => [['unit_id' => $unit->id]],
        ])->assertCreated()->json('data');

        // Still reserved — the release endpoint is for WON apartments only.
        $this->postJson("/api/v1/deals/{$deal['id']}/items/{$deal['units'][0]['item_id']}/release")
            ->assertStatus(422);
    }

    public function test_extra_boxes_can_be_sold_onto_a_won_apartment(): void
    {
        // The client comes back later for a parking box: it sells right onto
        // the won apartment and the agreed price grows by the addition.
        [$project, $visit, $unit] = $this->projectWithVisitAndUnit(boxes: 1);
        $box = Box::query()->firstOrFail();
        Sanctum::actingAs($this->manager());

        $deal = $this->postJson("/api/v1/projects/{$project->id}/deals", [
            'visit_id' => $visit->id,
            'units' => [['unit_id' => $unit->id]],
        ])->assertCreated()->json('data');

        $item = $deal['units'][0]['item_id'];
        $this->postJson("/api/v1/deals/{$deal['id']}/close", [
            'outcome' => 'won',
            'items' => [['item_id' => $item, 'agreed_price' => '500000.00']],
        ])->assertOk();

        $this->postJson("/api/v1/deals/{$deal['id']}/items/{$item}/boxes/add", [
            'box_ids' => [$box->id],
            'added_price' => '25000.00',
        ])->assertOk()
            ->assertJsonPath('data.units.0.agreed_price', '525000.00')
            ->assertJsonPath('data.total_price', '525000.00');

        // The box is sold and linked to the apartment; the project restamps.
        $this->assertSame('sold', $box->fresh()->sale_status->value);
        $this->assertSame($unit->id, $box->fresh()->unit_id);
        $this->assertSame('525000.00', (string) $project->fresh()->total_price);

        // A reserved (not won) apartment refuses this path - use the boxes editor.
        $this->assertSame('won', $project->fresh()->stage->value);
    }

    public function test_the_apartments_boxes_can_be_changed_while_the_deal_is_open(): void
    {
        [$project, $visit, $unit] = $this->projectWithVisitAndUnit(boxes: 2);
        $first = Box::query()->orderBy('id')->firstOrFail();
        Sanctum::actingAs($this->agent());

        $deal = $this->postJson("/api/v1/projects/{$project->id}/deals", [
            'visit_id' => $visit->id,
            'units' => [['unit_id' => $unit->id, 'box_ids' => [$first->id]]],
        ])->assertCreated()->json('data');

        $unitItem = $deal['units'][0]['item_id'];
        $otherBox = Box::query()->where('id', '!=', $first->id)->firstOrFail();

        // Swap the apartment's box for the other one.
        $this->putJson("/api/v1/deals/{$deal['id']}/items/{$unitItem}/boxes", ['box_ids' => [$otherBox->id]])
            ->assertOk()
            ->assertJsonCount(1, 'data.boxes')
            ->assertJsonPath('data.boxes.0.id', $otherBox->id)
            ->assertJsonPath('data.boxes.0.parent_item_id', $unitItem);

        // The dropped box is released and unlinked; the new one linked.
        $this->assertSame('available', $first->fresh()->sale_status->value);
        $this->assertNull($first->fresh()->unit_id);
        $this->assertSame('reserved', $otherBox->fresh()->sale_status->value);
        $this->assertSame($unit->id, $otherBox->fresh()->unit_id);
    }

    public function test_a_closed_deal_shows_in_the_project_deal_list(): void
    {
        $deal = Deal::factory()->create();
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'projects.view_all']));

        $this->getJson("/api/v1/projects/{$deal->client_project_id}/deals")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $deal->id);
    }
}
