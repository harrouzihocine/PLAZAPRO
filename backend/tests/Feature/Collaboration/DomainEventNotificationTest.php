<?php

declare(strict_types=1);

namespace Tests\Feature\Collaboration;

use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Clients\Models\Desire;
use App\Modules\Inventory\Actions\CorrectUnit;
use App\Modules\Inventory\Actions\CreateBox;
use App\Modules\Inventory\Actions\CreateUnit;
use App\Modules\Inventory\Actions\UpdateBox;
use App\Modules\Inventory\Actions\UpdateUnit;
use App\Modules\Inventory\Models\Box;
use App\Modules\Inventory\Models\Location;
use App\Modules\Payments\Actions\RecordVersement;
use App\Modules\Settings\Models\DynamicListItem;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DomainEventNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_recording_a_payment_notifies_the_deals_owning_agent(): void
    {
        $agent = User::factory()->agent()->create();
        $client = Client::factory()->create(['assigned_agent_id' => $agent->id]);
        $project = ClientProject::factory()->create(['client_id' => $client->id]);
        $method = DynamicListItem::factory()->create();

        app(RecordVersement::class)->handle($project, [
            'amount' => '1500.00',
            'paid_on' => now()->toDateString(),
            'method_id' => $method->id,
        ], User::factory()->create());

        $this->assertSame(1, $agent->notifications()->count());
        $this->assertSame('payment', $agent->notifications()->first()->data['kind']);
    }

    public function test_a_new_unit_notifies_agents_whose_clients_desires_match(): void
    {
        $type = DynamicListItem::factory()->create();
        // Project type lives on the location the unit belongs to.
        $location = Location::factory()->create(['type_id' => $type->id]);

        // Agent A has a client whose desire matches; agent B's client wants a
        // different type and must not be notified.
        $agentA = User::factory()->agent()->create();
        $clientA = Client::factory()->create(['assigned_agent_id' => $agentA->id]);
        Desire::factory()->create([
            'client_id' => $clientA->id,
            'type_id' => $type->id,
            'budget_max' => '500000',
        ]);

        $agentB = User::factory()->agent()->create();
        $clientB = Client::factory()->create(['assigned_agent_id' => $agentB->id]);
        Desire::factory()->create([
            'client_id' => $clientB->id,
            'type_id' => DynamicListItem::factory()->create()->id, // different type
        ]);

        app(CreateUnit::class)->handle($location, [
            'reference' => 'A-101',
            'price' => '300000',
        ]);

        // Filter to the desire-match notification — every user also gets the
        // separate "new unit added" announcement (unit_published).
        $this->assertSame(1, $agentA->notifications()->where('data->kind', 'unit_match')->count());
        $this->assertSame(0, $agentB->notifications()->where('data->kind', 'unit_match')->count());
    }

    public function test_an_agent_with_two_matching_clients_is_notified_once(): void
    {
        $type = DynamicListItem::factory()->create();
        $location = Location::factory()->create(['type_id' => $type->id]);
        $agent = User::factory()->agent()->create();

        foreach (range(1, 2) as $i) {
            $client = Client::factory()->create(['assigned_agent_id' => $agent->id]);
            Desire::factory()->create(['client_id' => $client->id, 'type_id' => $type->id]);
        }

        app(CreateUnit::class)->handle($location, [
            'reference' => 'B-1',
            'price' => '100000',
        ]);

        // One match notification for the agent, not one per matching client
        // (the separate "new unit added" announcement is not counted here).
        $this->assertSame(1, $agent->notifications()->where('data->kind', 'unit_match')->count());
    }

    public function test_editing_a_units_specs_notifies_every_active_user(): void
    {
        $location = Location::factory()->create();
        $unit = app(CreateUnit::class)->handle($location, ['reference' => 'D-1', 'price' => '100000']);

        // Two staff besides the (implicit) editor — everyone should hear about it.
        $watcherA = User::factory()->create();
        $watcherB = User::factory()->create();
        $floor = DynamicListItem::factory()->create();

        app(UpdateUnit::class)->handle($unit, ['floor_id' => $floor->id]);

        $this->assertSame(1, $watcherA->notifications()->where('data->kind', 'unit_updated')->count());
        $this->assertSame(1, $watcherB->notifications()->where('data->kind', 'unit_updated')->count());
    }

    public function test_saving_a_unit_edit_with_no_changes_notifies_nobody(): void
    {
        $location = Location::factory()->create();
        $unit = app(CreateUnit::class)->handle($location, ['reference' => 'E-1', 'price' => '100000']);
        $watcher = User::factory()->create();

        // Re-submitting the same reference changes nothing → no bell.
        app(UpdateUnit::class)->handle($unit, ['reference' => 'E-1']);

        $this->assertSame(0, $watcher->notifications()->where('data->kind', 'unit_updated')->count());
    }

    public function test_correcting_a_units_price_notifies_every_active_user(): void
    {
        $location = Location::factory()->create();
        $unit = app(CreateUnit::class)->handle($location, ['reference' => 'F-1', 'price' => '100000']);
        $watcher = User::factory()->create();

        app(CorrectUnit::class)->handle($unit, ['price' => '90000', 'reason' => 'Price drop']);

        $this->assertSame(1, $watcher->notifications()->where('data->kind', 'unit_updated')->count());
    }

    public function test_adding_a_box_notifies_every_active_user(): void
    {
        $location = Location::factory()->create();
        $watcher = User::factory()->create();

        app(CreateBox::class)->handle($location, ['reference' => 'P-1', 'price' => '10000']);

        $this->assertSame(1, $watcher->notifications()->where('data->kind', 'box_published')->count());
    }

    public function test_editing_a_box_notifies_every_active_user(): void
    {
        $box = Box::factory()->create(['reference' => 'P-2']);
        $watcher = User::factory()->create();

        app(UpdateBox::class)->handle($box, ['price' => '12000']);

        $this->assertSame(1, $watcher->notifications()->where('data->kind', 'box_updated')->count());
    }

    public function test_saving_a_box_edit_with_no_changes_notifies_nobody(): void
    {
        $box = Box::factory()->create(['reference' => 'P-3']);
        $watcher = User::factory()->create();

        app(UpdateBox::class)->handle($box, ['reference' => 'P-3']);

        $this->assertSame(0, $watcher->notifications()->where('data->kind', 'box_updated')->count());
    }

    public function test_repricing_a_unit_into_budget_notifies_the_matching_agent(): void
    {
        $type = DynamicListItem::factory()->create();
        $location = Location::factory()->create(['type_id' => $type->id]);
        $agent = User::factory()->agent()->create();
        $client = Client::factory()->create(['assigned_agent_id' => $agent->id]);
        Desire::factory()->create([
            'client_id' => $client->id,
            'type_id' => $type->id,
            'budget_max' => '200000',
        ]);

        // Created above budget → no match yet.
        $unit = app(CreateUnit::class)->handle($location, [
            'reference' => 'C-9',
            'price' => '300000',
        ]);
        $this->assertSame(0, $agent->notifications()->where('data->kind', 'unit_match')->count());

        // Repriced into budget → now matches (repricing raises no new-unit alert).
        app(CorrectUnit::class)->handle($unit, ['price' => '180000', 'reason' => 'Price drop']);

        $this->assertSame(1, $agent->notifications()->where('data->kind', 'unit_match')->count());
        $this->assertSame(
            'unit_match',
            $agent->notifications()->where('data->kind', 'unit_match')->first()->data['kind'],
        );
    }
}
