<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Modules\Clients\Models\Client;
use App\Modules\Pipeline\Models\Call;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * X-Idempotency-Key replay protection on the offline-queueable routes: a
 * replayed successful write returns the stored response instead of running
 * again; failures are never stored so a retry re-hits the live guards.
 */
class IdempotencyKeyTest extends TestCase
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

    private function callPayload(): array
    {
        return [
            'direction' => 'outbound',
            'closure' => ['type' => 'desire', 'desire' => ['notes' => 'Wants a 3-room near the centre.']],
        ];
    }

    public function test_the_same_key_replays_the_stored_response_without_reapplying(): void
    {
        $client = Client::factory()->create();
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'calls.log']));
        $key = (string) Str::uuid();

        $first = $this->withHeader('X-Idempotency-Key', $key)
            ->postJson("/api/v1/clients/{$client->id}/calls", $this->callPayload())
            ->assertCreated();

        $second = $this->withHeader('X-Idempotency-Key', $key)
            ->postJson("/api/v1/clients/{$client->id}/calls", $this->callPayload())
            ->assertCreated()
            ->assertHeader('X-Idempotency-Replayed', 'true');

        // One call row, byte-identical response.
        $this->assertSame(1, Call::count());
        $this->assertSame($first->getContent(), $second->getContent());
        $this->assertDatabaseCount('idempotency_keys', 1);
    }

    public function test_different_keys_apply_independently(): void
    {
        $client = Client::factory()->create();
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'calls.log']));

        $this->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/clients/{$client->id}/calls", $this->callPayload())->assertCreated();
        $this->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/clients/{$client->id}/calls", $this->callPayload())->assertCreated();

        $this->assertSame(2, Call::count());
    }

    public function test_keys_are_scoped_per_user(): void
    {
        $client = Client::factory()->create();
        $key = (string) Str::uuid();

        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'calls.log']));
        $this->withHeader('X-Idempotency-Key', $key)
            ->postJson("/api/v1/clients/{$client->id}/calls", $this->callPayload())->assertCreated();

        // A different user reusing the same UUID gets their own execution.
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'calls.log']));
        $this->withHeader('X-Idempotency-Key', $key)
            ->postJson("/api/v1/clients/{$client->id}/calls", $this->callPayload())->assertCreated();

        $this->assertSame(2, Call::count());
        $this->assertDatabaseCount('idempotency_keys', 2);
    }

    public function test_failures_are_not_stored_so_a_retry_re_evaluates_the_guards(): void
    {
        $client = Client::factory()->create();
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'calls.log']));
        $key = (string) Str::uuid();

        // Missing closure/next_action → 422 from the live guard.
        $this->withHeader('X-Idempotency-Key', $key)
            ->postJson("/api/v1/clients/{$client->id}/calls", ['direction' => 'outbound'])
            ->assertStatus(422);

        $this->assertDatabaseCount('idempotency_keys', 0);

        // The SAME key retried with a fixed payload now succeeds — the 422 was
        // never cached.
        $this->withHeader('X-Idempotency-Key', $key)
            ->postJson("/api/v1/clients/{$client->id}/calls", $this->callPayload())
            ->assertCreated();

        $this->assertSame(1, Call::count());
    }

    public function test_missing_or_malformed_keys_pass_through_untouched(): void
    {
        $client = Client::factory()->create();
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'calls.log']));

        // No header at all.
        $this->postJson("/api/v1/clients/{$client->id}/calls", $this->callPayload())->assertCreated();
        // Not a UUID — ignored, executed normally.
        $this->withHeader('X-Idempotency-Key', 'not-a-uuid')
            ->postJson("/api/v1/clients/{$client->id}/calls", $this->callPayload())->assertCreated();

        $this->assertSame(2, Call::count());
        $this->assertDatabaseCount('idempotency_keys', 0);
    }
}
