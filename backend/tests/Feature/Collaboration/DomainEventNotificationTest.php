<?php

declare(strict_types=1);

namespace Tests\Feature\Collaboration;

use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Clients\Models\Desire;
use App\Modules\Inventory\Actions\CorrectUnit;
use App\Modules\Inventory\Actions\CreateUnit;
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
        $location = Location::factory()->create();

        // Agent A has a client whose desire matches; agent B's client wants a
        // different type and must not be notified.
        $agentA = User::factory()->agent()->create();
        $clientA = Client::factory()->create(['assigned_agent_id' => $agentA->id]);
        Desire::factory()->create([
            'client_id' => $clientA->id,
            'type_id' => $type->id,
            'rooms_min' => 2,
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
            'type_id' => $type->id,
            'rooms' => 3,
            'price' => '300000',
        ]);

        $this->assertSame(1, $agentA->notifications()->count());
        $this->assertSame('unit_match', $agentA->notifications()->first()->data['kind']);
        $this->assertSame(0, $agentB->notifications()->count());
    }

    public function test_an_agent_with_two_matching_clients_is_notified_once(): void
    {
        $type = DynamicListItem::factory()->create();
        $location = Location::factory()->create();
        $agent = User::factory()->agent()->create();

        foreach (range(1, 2) as $i) {
            $client = Client::factory()->create(['assigned_agent_id' => $agent->id]);
            Desire::factory()->create(['client_id' => $client->id, 'type_id' => $type->id]);
        }

        app(CreateUnit::class)->handle($location, [
            'reference' => 'B-1',
            'type_id' => $type->id,
            'rooms' => 2,
            'price' => '100000',
        ]);

        // One notification for the agent, not one per matching client.
        $this->assertSame(1, $agent->notifications()->count());
    }

    public function test_repricing_a_unit_into_budget_notifies_the_matching_agent(): void
    {
        $type = DynamicListItem::factory()->create();
        $location = Location::factory()->create();
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
            'type_id' => $type->id,
            'rooms' => 2,
            'price' => '300000',
        ]);
        $this->assertSame(0, $agent->notifications()->count());

        // Repriced into budget → now matches.
        app(CorrectUnit::class)->handle($unit, ['price' => '180000', 'reason' => 'Price drop']);

        $this->assertSame(1, $agent->notifications()->count());
        $this->assertSame('unit_match', $agent->notifications()->first()->data['kind']);
    }
}
