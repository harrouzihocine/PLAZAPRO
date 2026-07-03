<?php

declare(strict_types=1);

namespace Tests\Feature\Clients;

use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Clients\Models\ShortlistItem;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase-6 closure queue: the deal list surfaces how many liked properties await a
 * won/lost decision (pending_closure_count) and how many prospects are still in
 * play at all (open_prospect_count) — both derived from shortlist states.
 */
class ClosureQueueTest extends TestCase
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

    private function itemInState(ClientProject $project, string $state): ShortlistItem
    {
        return ShortlistItem::factory()->create([
            'client_project_id' => $project->id,
            'shortlistable_type' => 'unit',
            'shortlistable_id' => Unit::factory()->create()->id,
            'state' => $state,
        ]);
    }

    public function test_the_deal_list_exposes_the_closure_queue_counts(): void
    {
        $client = Client::factory()->create();
        $project = ClientProject::factory()->create(['client_id' => $client->id]);
        $this->itemInState($project, 'shortlisted');
        $this->itemInState($project, 'not_visited');
        $this->itemInState($project, 'visited_interested');
        $this->itemInState($project, 'visited_interested');
        $this->itemInState($project, 'visited_not_interested'); // out of play
        $this->itemInState($project, 'lost');                   // closed
        Sanctum::actingAs($this->userWithPermissions(['clients.view']));

        $this->getJson("/api/v1/clients/{$client->id}/projects")
            ->assertOk()
            ->assertJsonPath('data.0.pending_closure_count', 2)
            ->assertJsonPath('data.0.open_prospect_count', 4);
    }

    public function test_closing_an_interested_property_updates_the_counts(): void
    {
        $client = Client::factory()->create();
        $project = ClientProject::factory()->create(['client_id' => $client->id]);
        $interested = $this->itemInState($project, 'visited_interested');
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'clients.manage']));

        $this->postJson("/api/v1/shortlist-items/{$interested->id}/outcome", ['outcome' => 'lost'])
            ->assertSuccessful();

        // The queue empties: nothing pending, nothing in play.
        $this->getJson("/api/v1/clients/{$client->id}/projects")
            ->assertOk()
            ->assertJsonPath('data.0.pending_closure_count', 0)
            ->assertJsonPath('data.0.open_prospect_count', 0);
    }

    public function test_cancelled_items_do_not_count(): void
    {
        $client = Client::factory()->create();
        $project = ClientProject::factory()->create(['client_id' => $client->id]);
        $this->itemInState($project, 'visited_interested')->cancel('Removed from shortlist');
        Sanctum::actingAs($this->userWithPermissions(['clients.view']));

        $this->getJson("/api/v1/clients/{$client->id}/projects")
            ->assertOk()
            ->assertJsonPath('data.0.pending_closure_count', 0)
            ->assertJsonPath('data.0.open_prospect_count', 0);
    }
}
