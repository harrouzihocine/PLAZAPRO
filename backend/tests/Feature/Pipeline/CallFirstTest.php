<?php

declare(strict_types=1);

namespace Tests\Feature\Pipeline;

use App\Modules\Clients\Models\Client;
use App\Modules\Pipeline\Models\Call;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Workflow rule: a call log is the very first entity captured for a new client.
 * Until one exists, visits, deals and desires are all rejected at the request
 * boundary — the qualification happens on the phone first.
 */
class CallFirstTest extends TestCase
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

    public function test_a_visit_cannot_be_scheduled_before_the_first_call(): void
    {
        $client = Client::factory()->create();
        $agent = User::factory()->agent()->create();
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'visits.assign']));

        $payload = [
            'client_id' => $client->id, 'type' => 'office',
            'agent_id' => $agent->id, 'scheduled_at' => now()->addDay()->toDateTimeString(),
        ];

        $this->postJson('/api/v1/visits', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('client_id');

        Call::factory()->create(['client_id' => $client->id]);

        $this->postJson('/api/v1/visits', $payload)->assertCreated();
    }

    public function test_a_deal_cannot_be_opened_before_the_first_call(): void
    {
        $client = Client::factory()->create();
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'clients.manage']));

        $this->postJson("/api/v1/clients/{$client->id}/projects", [])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('client_id');

        Call::factory()->create(['client_id' => $client->id]);

        $this->postJson("/api/v1/clients/{$client->id}/projects", [])->assertCreated();
    }

    public function test_a_desire_cannot_be_captured_before_the_first_call(): void
    {
        $client = Client::factory()->create();
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'clients.create']));

        $this->putJson("/api/v1/clients/{$client->id}/desire", ['floor_pref' => 'floor_2'])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('client_id');

        Call::factory()->create(['client_id' => $client->id]);

        $this->putJson("/api/v1/clients/{$client->id}/desire", ['floor_pref' => 'floor_2'])->assertOk();
    }

    public function test_a_cancelled_call_does_not_satisfy_the_rule(): void
    {
        $client = Client::factory()->create();
        Call::factory()->create(['client_id' => $client->id, 'status' => 'cancelled']);
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'clients.manage']));

        $this->postJson("/api/v1/clients/{$client->id}/projects", [])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('client_id');
    }

    public function test_the_client_resource_exposes_has_calls(): void
    {
        $without = Client::factory()->create();
        $with = Client::factory()->create();
        Call::factory()->create(['client_id' => $with->id]);
        Sanctum::actingAs($this->userWithPermissions(['clients.view']));

        $this->getJson("/api/v1/clients/{$without->id}")
            ->assertOk()->assertJsonPath('data.has_calls', false);
        $this->getJson("/api/v1/clients/{$with->id}")
            ->assertOk()->assertJsonPath('data.has_calls', true);
    }
}
