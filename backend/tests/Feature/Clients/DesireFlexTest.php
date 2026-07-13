<?php

declare(strict_types=1);

namespace Tests\Feature\Clients;

use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Clients\Models\Desire;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Pipeline\Models\Call;
use App\Modules\Settings\Models\Commune;
use App\Modules\Settings\Models\Wilaya;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DesireFlexTest extends TestCase
{
    use RefreshDatabase;

    /** @param  list<string>  $slugs */
    private function userWith(array $slugs, bool $isAgent = false): User
    {
        $role = Role::factory()->create(['is_agent' => $isAgent]);
        $role->permissions()->sync(collect($slugs)->map(
            fn (string $slug) => Permission::firstOrCreate(['slug' => $slug], ['name' => $slug])->id,
        ));

        return User::factory()->create(['role_id' => $role->id]);
    }

    public function test_shifting_a_deal_to_desire_archives_it_and_captures_the_desire(): void
    {
        $client = Client::factory()->create();
        $project = ClientProject::factory()->create(['client_id' => $client->id]);
        Sanctum::actingAs($this->userWith(['clients.view', 'projects.manage', 'clients.create']));

        $this->postJson("/api/v1/projects/{$project->id}/shift-to-desire", ['budget_max' => '3000000', 'notes' => 'Wants something cheaper'])
            ->assertSuccessful();

        $this->assertDatabaseHas('client_projects', [
            'id' => $project->id, 'status' => 'archived', 'cancellation_reason' => 'Shifted to desire',
        ]);
        $this->assertDatabaseHas('desires', ['client_id' => $client->id, 'budget_max' => '3000000.00']);
    }

    public function test_shift_to_desire_rejects_a_commune_outside_the_picked_wilayas(): void
    {
        $client = Client::factory()->create();
        $project = ClientProject::factory()->create(['client_id' => $client->id]);
        $wilaya = Wilaya::factory()->create();
        $other = Wilaya::factory()->create();
        $commune = Commune::factory()->create(['wilaya_id' => $other->id]);
        Sanctum::actingAs($this->userWith(['clients.view', 'projects.manage', 'clients.create']));

        $this->postJson("/api/v1/projects/{$project->id}/shift-to-desire", [
            'wilaya_ids' => [$wilaya->id],
            'commune_ids' => [$commune->id],
            'notes' => 'Commune outside the picked wilayas',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('commune_ids');
    }

    public function test_the_board_paginates_and_filters_server_side(): void
    {
        // The board can carry thousands of waiting clients — it pages (lazy
        // loading on the frontend) and filters search/unassigned on the server.
        $agent = $this->userWith(['clients.view'], isAgent: true);
        Unit::factory()->create(['price_semi_fini' => '1000.00', 'sale_status' => 'available']);

        $waiting = [
            ['first_name' => 'Amine', 'phone' => '0551000001', 'assigned_agent_id' => $agent->id],
            ['first_name' => 'Bilal', 'phone' => '0551000002', 'assigned_agent_id' => null],
            ['first_name' => 'Chafik', 'phone' => '0551000003', 'assigned_agent_id' => null],
        ];
        foreach ($waiting as $attrs) {
            $client = Client::factory()->create($attrs);
            Desire::factory()->create([
                'client_id' => $client->id, 'client_project_id' => null,
                'budget_min' => null, 'budget_max' => '2000.00',
            ]);
        }

        Sanctum::actingAs($this->userWith(['oversight.matches']));

        // Page 1 of 2 — the meta carries the full total for the "Showing X of Y" line.
        $this->getJson('/api/v1/desires/matches?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.meta.total', 3)
            ->assertJsonPath('data.meta.last_page', 2)
            ->assertJsonPath('data.items.0.client.full_name', fn ($n) => str_contains((string) $n, 'Amine'));

        $this->getJson('/api/v1/desires/matches?per_page=2&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.meta.current_page', 2);

        // Unassigned-only drops the delegated lead.
        $this->getJson('/api/v1/desires/matches?unassigned=1')
            ->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.meta.total', 2);

        // Search matches the client's name or phone fragment, however written.
        $this->getJson('/api/v1/desires/matches?search=chafik')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.client.phone', '+213 551 00 00 03');

        $this->getJson('/api/v1/desires/matches?search=51000002')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.client.phone', '+213 551 00 00 02');

        // The waiting-since window keys on the desire's capture date.
        Desire::query()->whereHas('client', fn ($c) => $c->where('first_name', 'Amine'))
            ->update(['created_at' => now()->subDays(30)]);

        $this->getJson('/api/v1/desires/matches?from='.now()->subDay()->toDateString())
            ->assertOk()
            ->assertJsonPath('data.meta.total', 2);

        $this->getJson('/api/v1/desires/matches?to='.now()->subDays(7)->toDateString())
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.client.phone', '+213 551 00 00 01');
    }

    public function test_desire_matches_board_is_company_wide_for_oversight(): void
    {
        $overseer = $this->userWith(['oversight.matches']);
        $agent = $this->userWith(['clients.view'], isAgent: true);
        $client = Client::factory()->create(['assigned_agent_id' => $agent->id]);
        Desire::factory()->create([
            'client_id' => $client->id, 'client_project_id' => null,
            'budget_min' => null, 'budget_max' => '2000.00',
        ]);
        Unit::factory()->create(['price_semi_fini' => '1000.00', 'sale_status' => 'available']);

        // The oversight board lists every waiting client with a match, whoever owns it.
        Sanctum::actingAs($overseer);
        $this->getJson('/api/v1/desires/matches')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.client.id', $client->id);

        // A regular agent (no oversight.matches) cannot open the board at all.
        Sanctum::actingAs($agent);
        $this->getJson('/api/v1/desires/matches')->assertForbidden();
    }

    public function test_summary_carries_the_company_wide_matches_count_for_the_sidebar_badge(): void
    {
        $overseer = $this->userWith(['oversight.matches']);
        $client = Client::factory()->create();
        Desire::factory()->create([
            'client_id' => $client->id, 'client_project_id' => null,
            'budget_min' => null, 'budget_max' => '2000.00',
        ]);
        Unit::factory()->create(['price_semi_fini' => '1000.00', 'sale_status' => 'available']);

        // The oversight badge counts every waiting client with a match, company-wide.
        Sanctum::actingAs($overseer);
        $this->getJson('/api/v1/oversight/summary')->assertOk()->assertJsonPath('data.matches', 1);

        // Without oversight.matches (the board's own gate) the key is absent, not zero.
        Sanctum::actingAs($this->userWith(['clients.view', 'units.view']));
        $this->assertArrayNotHasKey('matches', $this->getJson('/api/v1/oversight/summary')->assertOk()->json('data'));
    }

    public function test_the_board_ships_the_full_property_card_and_the_desire_brief(): void
    {
        $agent = $this->userWith(['clients.view', 'units.view', 'oversight.matches'], isAgent: true);
        $client = Client::factory()->create(['assigned_agent_id' => $agent->id]);
        Desire::factory()->create([
            'client_id' => $client->id, 'client_project_id' => null,
            'budget_min' => null, 'budget_max' => null, 'notes' => 'Wants something nice',
        ]);
        $unit = Unit::factory()->create(['price_semi_fini' => '1000.00', 'sale_status' => 'available', 'area_sqm' => 75]);

        Sanctum::actingAs($agent);

        // Reference/price alone told an agent nothing about fit — the card now
        // ships area/status/rank, and the desire rides through so "why this
        // matched" doesn't need a trip to the client file.
        $this->getJson('/api/v1/desires/matches')
            ->assertOk()
            ->assertJsonPath('data.items.0.matches.0.id', $unit->id)
            ->assertJsonPath('data.items.0.matches.0.area_sqm', '75.00')
            ->assertJsonPath('data.items.0.matches.0.sale_status', 'available')
            ->assertJsonPath('data.items.0.matches.0.best_match', true)
            ->assertJsonPath('data.items.0.desire.notes', 'Wants something nice');
    }

    public function test_the_board_links_back_to_the_project_the_desire_was_shifted_from(): void
    {
        $agent = $this->userWith(['clients.view', 'units.view', 'projects.manage', 'clients.create', 'oversight.matches'], isAgent: true);
        $client = Client::factory()->create(['assigned_agent_id' => $agent->id]);
        $location = Location::factory()->create();
        $project = ClientProject::factory()->create(['client_id' => $client->id, 'location_id' => $location->id]);
        Sanctum::actingAs($agent);

        $this->postJson("/api/v1/projects/{$project->id}/shift-to-desire", ['notes' => 'Wants something cheaper'])
            ->assertSuccessful();
        Unit::factory()->create(['price_semi_fini' => '1000.00', 'sale_status' => 'available']);

        // A desire shifted off a project points back to it — the board's link.
        $this->getJson('/api/v1/desires/matches')
            ->assertOk()
            ->assertJsonPath('data.items.0.origin_project.id', $project->id)
            ->assertJsonPath('data.items.0.origin_project.label', $location->name);

        // A desire captured straight off a call (no prior project) has none.
        $freshClient = Client::factory()->create(['assigned_agent_id' => $agent->id]);
        Desire::factory()->create([
            'client_id' => $freshClient->id, 'client_project_id' => null,
            'budget_min' => null, 'budget_max' => null,
        ]);
        $this->getJson('/api/v1/desires/matches')
            ->assertOk()
            ->assertJsonPath('data.items.1.origin_project', null);
    }

    public function test_shifting_marks_the_project_as_waiting_on_desire(): void
    {
        $project = ClientProject::factory()->create();
        Sanctum::actingAs($this->userWith(['clients.view', 'projects.manage', 'clients.create', 'projects.view_all']));

        $this->postJson("/api/v1/projects/{$project->id}/shift-to-desire", ['budget_max' => '100', 'notes' => 'Tiny budget for now'])
            ->assertSuccessful();

        $project->refresh();
        $this->assertNotNull($project->closed_to_desire_at);
        $this->assertTrue($project->isArchived());

        // The badge rides on the resource: step = desire.
        $this->getJson("/api/v1/clients/{$project->client_id}/projects?status=archived")
            ->assertOk()
            ->assertJsonPath('data.0.step', 'desire')
            ->assertJsonPath('data.0.closed_to_desire', true);
    }

    public function test_a_new_call_reopens_the_project_from_the_desire_list(): void
    {
        $client = Client::factory()->create();
        $project = ClientProject::factory()->create(['client_id' => $client->id]);
        Sanctum::actingAs($this->userWith(['clients.view', 'projects.manage', 'clients.create', 'calls.log']));

        $this->postJson("/api/v1/projects/{$project->id}/shift-to-desire", ['budget_max' => '100', 'notes' => 'Tiny budget for now'])
            ->assertSuccessful();

        // The reconnect call (after a desire match) reactivates the project and
        // attaches to it — the profile reopens from the desire list.
        $this->postJson("/api/v1/clients/{$client->id}/calls", [
            'direction' => 'outbound',
            'next_action' => ['type' => 'call', 'due_date' => now()->addDay()->toDateString()],
        ])->assertCreated();

        $project->refresh();
        $this->assertTrue($project->isActive());
        $this->assertNull($project->closed_to_desire_at);
        $this->assertDatabaseHas('calls', [
            'client_id' => $client->id, 'client_project_id' => $project->id,
        ]);
    }

    // Desire Matches "Reconnect & qualify": a call that qualifies with properties
    // (the Desire Matches board's action) both opens the project AND closes the
    // waiting-list entry — otherwise the same client re-surfaces on the board on
    // the very next matcher run despite already being back in an active pipeline.

    public function test_reconnecting_with_properties_closes_the_waiting_desire(): void
    {
        $client = Client::factory()->create();
        Desire::factory()->create(['client_id' => $client->id, 'client_project_id' => null]);
        $unit = Unit::factory()->create();
        Sanctum::actingAs($this->userWith(['clients.view', 'calls.log']));

        $this->postJson("/api/v1/clients/{$client->id}/calls", [
            'direction' => 'outbound',
            'properties' => [['shortlistable_type' => 'unit', 'shortlistable_id' => $unit->id]],
            'next_action' => ['type' => 'call', 'due_date' => now()->addDay()->toDateString()],
        ])->assertCreated();

        $this->assertDatabaseHas('desires', [
            'client_id' => $client->id, 'status' => 'cancelled',
        ]);
        $this->assertSame(1, ClientProject::query()->where('client_id', $client->id)->count());
    }

    public function test_a_reconnected_client_drops_off_the_desire_matches_board(): void
    {
        $agent = $this->userWith(['clients.view', 'units.view', 'calls.log', 'oversight.matches'], isAgent: true);
        $client = Client::factory()->create(['assigned_agent_id' => $agent->id]);
        Desire::factory()->create([
            'client_id' => $client->id, 'client_project_id' => null,
            'budget_min' => null, 'budget_max' => null,
        ]);
        $unit = Unit::factory()->create(['price_semi_fini' => '1000.00', 'sale_status' => 'available']);
        Sanctum::actingAs($agent);

        $this->getJson('/api/v1/desires/matches')->assertOk()->assertJsonCount(1, 'data.items');

        $this->postJson("/api/v1/clients/{$client->id}/calls", [
            'direction' => 'outbound',
            'properties' => [['shortlistable_type' => 'unit', 'shortlistable_id' => $unit->id]],
            'next_action' => ['type' => 'call', 'due_date' => now()->addDay()->toDateString()],
        ])->assertCreated();

        $this->getJson('/api/v1/desires/matches')->assertOk()->assertJsonCount(0, 'data.items');
    }

    public function test_a_closed_out_desire_is_revived_not_left_stuck_cancelled(): void
    {
        $client = Client::factory()->create();
        Call::factory()->create(['client_id' => $client->id]); // call-first rule
        $desire = Desire::factory()->create(['client_id' => $client->id, 'client_project_id' => null]);
        $desire->cancel('Reconnected — back in an active project');
        Sanctum::actingAs($this->userWith(['clients.view', 'clients.create']));

        // updateOrCreate() finds the SAME row (same client_id + null project) —
        // it must come back active, not silently stay cancelled forever.
        $this->putJson("/api/v1/clients/{$client->id}/desire", ['budget_max' => '4000000', 'notes' => 'Back on the market'])
            ->assertOk();

        $this->assertDatabaseHas('desires', [
            'client_id' => $client->id, 'status' => 'active',
        ]);
        $this->assertSame(1, Desire::where('client_id', $client->id)->count());
    }
}
