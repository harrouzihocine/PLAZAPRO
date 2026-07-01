<?php

declare(strict_types=1);

namespace Tests\Feature\Pipeline;

use App\Modules\Clients\Models\Client;
use App\Modules\Pipeline\Models\NextAction;
use App\Modules\Pipeline\Models\Visit;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InteractionTest extends TestCase
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
        return User::factory()->agent()->create();
    }

    private function nextActionPayload(User $assignee): array
    {
        return ['type' => 'follow_up', 'due_at' => now()->addDay()->toDateTimeString(), 'assigned_to' => $assignee->id];
    }

    public function test_logging_a_call_records_it_and_creates_a_pending_next_action(): void
    {
        $client = Client::factory()->create();
        $agent = $this->agent();
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'calls.log']));

        $this->postJson("/api/v1/clients/{$client->id}/calls", [
            'direction' => 'outbound',
            'next_action' => $this->nextActionPayload($agent),
        ])->assertCreated()->assertJsonPath('data.direction', 'outbound');

        $this->assertDatabaseHas('calls', ['client_id' => $client->id, 'direction' => 'outbound']);
        $this->assertSame(1, NextAction::query()->pending()
            ->where('subject_type', 'client')->where('subject_id', $client->id)->count());
    }

    public function test_logging_a_call_without_a_next_action_is_rejected(): void
    {
        $client = Client::factory()->create();
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'calls.log']));

        $this->postJson("/api/v1/clients/{$client->id}/calls", ['direction' => 'outbound'])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('next_action');

        $this->assertSame(0, NextAction::count());
    }

    public function test_a_subject_keeps_exactly_one_open_pending_next_action(): void
    {
        $client = Client::factory()->create();
        $agent = $this->agent();
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'calls.log']));

        $this->postJson("/api/v1/clients/{$client->id}/calls", ['direction' => 'outbound', 'next_action' => $this->nextActionPayload($agent)])->assertCreated();
        $this->postJson("/api/v1/clients/{$client->id}/calls", ['direction' => 'inbound', 'next_action' => $this->nextActionPayload($agent)])->assertCreated();

        // Two calls logged, but only one pending action for the subject; the first is done.
        $this->assertSame(1, NextAction::query()->pending()
            ->where('subject_type', 'client')->where('subject_id', $client->id)->count());
        $this->assertSame(1, NextAction::query()->where('state', 'done')
            ->where('subject_type', 'client')->where('subject_id', $client->id)->count());
    }

    public function test_a_visit_can_only_be_assigned_to_an_agent(): void
    {
        $client = Client::factory()->create();
        $nonAgent = $this->userWithPermissions(['clients.view']); // role not is_agent
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'visits.assign']));

        $this->postJson('/api/v1/visits', [
            'client_id' => $client->id, 'type' => 'office',
            'agent_id' => $nonAgent->id, 'scheduled_at' => now()->addDay()->toDateTimeString(),
        ])->assertStatus(422)->assertJsonValidationErrorFor('agent_id');
    }

    public function test_scheduling_an_apartment_visit_requires_a_unit(): void
    {
        $client = Client::factory()->create();
        $agent = $this->agent();
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'visits.assign']));

        $this->postJson('/api/v1/visits', [
            'client_id' => $client->id, 'type' => 'apartment',
            'agent_id' => $agent->id, 'scheduled_at' => now()->addDay()->toDateTimeString(),
        ])->assertStatus(422)->assertJsonValidationErrorFor('unit_id');
    }

    public function test_scheduling_a_valid_office_visit_works(): void
    {
        $client = Client::factory()->create();
        $agent = $this->agent();
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'visits.assign']));

        $this->postJson('/api/v1/visits', [
            'client_id' => $client->id, 'type' => 'office',
            'agent_id' => $agent->id, 'scheduled_at' => now()->addDay()->toDateTimeString(),
        ])->assertCreated()->assertJsonPath('data.type', 'office');
    }

    public function test_reassigning_a_visit_to_a_non_agent_is_rejected(): void
    {
        $visit = Visit::factory()->create();
        $nonAgent = $this->userWithPermissions(['clients.view']);
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'visits.assign']));

        $this->postJson("/api/v1/visits/{$visit->id}/assign", ['agent_id' => $nonAgent->id])
            ->assertStatus(422);
    }

    public function test_completing_a_visit_requires_a_next_action(): void
    {
        $visit = Visit::factory()->create();
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'visits.conduct']));

        $this->postJson("/api/v1/visits/{$visit->id}/complete", [])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('next_action');

        $this->assertNull($visit->fresh()->completed_at);
    }

    public function test_completing_a_visit_records_completion_and_a_next_action(): void
    {
        $visit = Visit::factory()->apartment()->create();
        $agent = $this->agent();
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'visits.conduct']));

        $this->postJson("/api/v1/visits/{$visit->id}/complete", [
            'next_action' => $this->nextActionPayload($agent),
        ])->assertOk()->assertJsonPath('data.is_completed', true);

        $this->assertNotNull($visit->fresh()->completed_at);
        $this->assertSame(1, NextAction::query()->pending()
            ->where('subject_type', 'client')->where('subject_id', $visit->client_id)->count());
    }

    public function test_logging_a_call_requires_calls_log(): void
    {
        $client = Client::factory()->create();
        Sanctum::actingAs($this->userWithPermissions(['clients.view']));

        $this->postJson("/api/v1/clients/{$client->id}/calls", ['direction' => 'outbound'])
            ->assertForbidden();
    }

    public function test_scheduling_a_visit_requires_visits_assign(): void
    {
        $client = Client::factory()->create();
        $agent = $this->agent();
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'visits.conduct']));

        $this->postJson('/api/v1/visits', [
            'client_id' => $client->id, 'type' => 'office',
            'agent_id' => $agent->id, 'scheduled_at' => now()->addDay()->toDateTimeString(),
        ])->assertForbidden();
    }

    public function test_timeline_returns_calls_visits_and_open_actions(): void
    {
        $client = Client::factory()->create();
        $agent = $this->agent();
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'calls.log']));
        $this->postJson("/api/v1/clients/{$client->id}/calls", ['direction' => 'outbound', 'next_action' => $this->nextActionPayload($agent)])->assertCreated();
        Visit::factory()->create(['client_id' => $client->id]);

        Sanctum::actingAs($this->userWithPermissions(['clients.view']));
        $this->getJson("/api/v1/clients/{$client->id}/timeline")
            ->assertOk()
            ->assertJsonCount(1, 'data.calls')
            ->assertJsonCount(1, 'data.visits')
            ->assertJsonCount(1, 'data.next_actions');
    }
}
