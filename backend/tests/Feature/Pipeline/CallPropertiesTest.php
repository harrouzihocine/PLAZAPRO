<?php

declare(strict_types=1);

namespace Tests\Feature\Pipeline;

use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Clients\Models\Desire;
use App\Modules\Clients\Models\ShortlistItem;
use App\Modules\Inventory\Models\Box;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Pipeline\Models\Call;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase-2 qualification happens on the call: the agent multi-selects specific
 * properties (Branch B → shortlist on the auto-created deal) or captures the
 * desire profile (Branch A), all in the one call payload, atomically.
 */
class CallPropertiesTest extends TestCase
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

    private function nextAction(): array
    {
        return ['type' => 'call', 'due_date' => now()->addDay()->toDateString(), 'assigned_to' => User::factory()->agent()->create()->id];
    }

    public function test_call_properties_create_the_deal_and_shortlist_rows(): void
    {
        $client = Client::factory()->create();
        $unit = Unit::factory()->create();
        $box = Box::factory()->create();
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'calls.log']));

        $this->postJson("/api/v1/clients/{$client->id}/calls", [
            'direction' => 'outbound',
            'properties' => [
                ['shortlistable_type' => 'unit', 'shortlistable_id' => $unit->id],
                ['shortlistable_type' => 'box', 'shortlistable_id' => $box->id],
            ],
            'next_action' => $this->nextAction(),
        ])->assertCreated();

        // A deal was auto-created at `lead` and the call linked to it.
        $project = ClientProject::query()->where('client_id', $client->id)->sole();
        $this->assertSame('lead', $project->stage->value);
        $call = Call::query()->where('client_id', $client->id)->sole();
        $this->assertSame($project->id, $call->client_project_id);

        // Both properties are shortlisted with call provenance.
        $this->assertDatabaseHas('shortlist_items', [
            'client_project_id' => $project->id, 'call_id' => $call->id,
            'shortlistable_type' => 'unit', 'shortlistable_id' => $unit->id, 'state' => 'shortlisted',
        ]);
        $this->assertDatabaseHas('shortlist_items', [
            'client_project_id' => $project->id, 'call_id' => $call->id,
            'shortlistable_type' => 'box', 'shortlistable_id' => $box->id, 'state' => 'shortlisted',
        ]);

        // The enforced next action follows the deal, not the bare client.
        $this->assertDatabaseHas('next_actions', [
            'subject_type' => 'client_project', 'subject_id' => $project->id, 'state' => 'pending',
        ]);
    }

    public function test_later_calls_add_without_duplicating_and_reuse_the_open_deal(): void
    {
        $client = Client::factory()->create();
        $unitA = Unit::factory()->create();
        $unitB = Unit::factory()->create();
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'calls.log']));

        $post = fn (array $properties) => $this->postJson("/api/v1/clients/{$client->id}/calls", [
            'direction' => 'outbound', 'properties' => $properties, 'next_action' => $this->nextAction(),
        ])->assertCreated();

        $post([['shortlistable_type' => 'unit', 'shortlistable_id' => $unitA->id]]);
        // Second call re-sends A and adds B.
        $post([
            ['shortlistable_type' => 'unit', 'shortlistable_id' => $unitA->id],
            ['shortlistable_type' => 'unit', 'shortlistable_id' => $unitB->id],
        ]);

        $this->assertSame(1, ClientProject::query()->where('client_id', $client->id)->count());
        $this->assertSame(2, ShortlistItem::query()->active()->count());
        $this->assertSame(1, ShortlistItem::query()->active()->where('shortlistable_id', $unitA->id)->count());
    }

    public function test_a_call_can_capture_the_desire_profile_atomically(): void
    {
        $client = Client::factory()->create();
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'calls.log']));

        $this->postJson("/api/v1/clients/{$client->id}/calls", [
            'direction' => 'inbound',
            'desire' => ['floor_pref' => 'floor_2', 'budget_min' => 4000000, 'budget_max' => 6000000, 'notes' => 'Budget 4-6M, second floor'],
            'next_action' => $this->nextAction(),
        ])->assertCreated();

        $desire = Desire::query()->where('client_id', $client->id)->sole();
        $this->assertSame('floor_2', $desire->floor_pref);
        // Branch A creates no deal.
        $this->assertSame(0, ClientProject::query()->where('client_id', $client->id)->count());
    }

    public function test_an_unknown_property_rolls_the_whole_call_back(): void
    {
        $client = Client::factory()->create();
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'calls.log']));

        $this->postJson("/api/v1/clients/{$client->id}/calls", [
            'direction' => 'outbound',
            'properties' => [['shortlistable_type' => 'unit', 'shortlistable_id' => 999999]],
            'next_action' => $this->nextAction(),
        ])->assertStatus(422);

        $this->assertSame(0, Call::count());
        $this->assertSame(0, ClientProject::count());
        $this->assertSame(0, ShortlistItem::count());
    }
}
