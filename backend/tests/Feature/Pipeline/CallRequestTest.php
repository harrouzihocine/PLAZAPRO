<?php

declare(strict_types=1);

namespace Tests\Feature\Pipeline;

use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Collaboration\Notifications\DomainNotification;
use App\Modules\Pipeline\Enums\CallRequestStatus;
use App\Modules\Pipeline\Events\CallRequestClosed;
use App\Modules\Pipeline\Models\CallRequest;
use App\Modules\Pipeline\Notifications\CallRequestNotification;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Click-to-call: the web phone icon pushes a client's number to the agent's
 * own phone (sent), the shell reports the dialer opened (dialed → the
 * cross-device "log this call?" prompt), answering the prompt closes the
 * request (logged / dismissed).
 */
class CallRequestTest extends TestCase
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

    /** An agent with calls.log, a registered device, and FCM configured. */
    private function actingAgent(): User
    {
        config(['services.fcm.credentials' => '/tmp/fake-credentials.json']);

        $agent = $this->userWithPermissions(['clients.view', 'calls.log']);
        $agent->deviceTokens()->create(['token' => 'test-device-token', 'platform' => 'android']);
        Sanctum::actingAs($agent);

        return $agent;
    }

    public function test_sending_a_call_request_pushes_the_number_to_the_phone(): void
    {
        Notification::fake();
        $agent = $this->actingAgent();
        $client = Client::factory()->create(['assigned_agent_id' => $agent->id]);

        $this->postJson("/api/v1/clients/{$client->id}/call-requests")
            ->assertCreated()
            ->assertJsonPath('data.status', 'sent')
            ->assertJsonPath('data.link', "/clients/{$client->id}?logcall=".CallRequest::first()->id);

        $this->assertDatabaseHas('call_requests', [
            'user_id' => $agent->id,
            'client_id' => $client->id,
            'phone' => $client->phone,
            'status' => 'sent',
        ]);

        Notification::assertSentTo($agent, CallRequestNotification::class, function ($n) use ($agent, $client) {
            $payload = $n->toFcm($agent);

            return $payload['kind'] === 'call_request'
                && $payload['phone'] === $client->phone
                && $payload['tag'] === 'call_request-'.$client->id;
        });
    }

    public function test_the_link_targets_the_single_active_project(): void
    {
        Notification::fake();
        $agent = $this->actingAgent();
        $client = Client::factory()->create(['assigned_agent_id' => $agent->id]);
        $project = ClientProject::factory()->create(['client_id' => $client->id]);

        $this->postJson("/api/v1/clients/{$client->id}/call-requests")
            ->assertCreated()
            ->assertJsonPath(
                'data.link',
                "/clients/{$client->id}/projects/{$project->id}?logcall=".CallRequest::first()->id,
            );
    }

    public function test_two_active_projects_fall_back_to_the_client_file_link(): void
    {
        Notification::fake();
        $agent = $this->actingAgent();
        $client = Client::factory()->create(['assigned_agent_id' => $agent->id]);
        ClientProject::factory()->count(2)->create(['client_id' => $client->id]);

        $this->postJson("/api/v1/clients/{$client->id}/call-requests")
            ->assertCreated()
            ->assertJsonPath('data.link', "/clients/{$client->id}?logcall=".CallRequest::first()->id);
    }

    public function test_a_reclick_reuses_the_open_request_instead_of_stacking(): void
    {
        Notification::fake();
        $agent = $this->actingAgent();
        $client = Client::factory()->create(['assigned_agent_id' => $agent->id]);

        $this->postJson("/api/v1/clients/{$client->id}/call-requests")->assertCreated();
        $this->postJson("/api/v1/clients/{$client->id}/call-requests")->assertCreated();

        $this->assertSame(1, CallRequest::count());
        Notification::assertSentToTimes($agent, CallRequestNotification::class, 2);
    }

    public function test_a_reclick_never_downgrades_a_dialed_request(): void
    {
        Notification::fake();
        $agent = $this->actingAgent();
        $client = Client::factory()->create(['assigned_agent_id' => $agent->id]);

        $this->postJson("/api/v1/clients/{$client->id}/call-requests")->assertCreated();
        $callRequest = CallRequest::sole();
        $this->postJson("/api/v1/call-requests/{$callRequest->id}/dialed")->assertOk();

        // Re-click after the dial: the push goes out again, but the row stays
        // dialed — the audit timestamp survives and the once-only prompt guard
        // holds (a second /dialed report must not re-prompt).
        $this->postJson("/api/v1/clients/{$client->id}/call-requests")
            ->assertCreated()
            ->assertJsonPath('data.status', 'dialed');

        $this->assertNotNull($callRequest->fresh()->dialed_at);

        $this->postJson("/api/v1/call-requests/{$callRequest->id}/dialed")->assertOk();
        Notification::assertSentToTimes($agent, DomainNotification::class, 1);
    }

    public function test_no_registered_device_is_a_friendly_422(): void
    {
        config(['services.fcm.credentials' => '/tmp/fake-credentials.json']);
        $agent = $this->userWithPermissions(['clients.view', 'calls.log']);
        Sanctum::actingAs($agent);
        $client = Client::factory()->create(['assigned_agent_id' => $agent->id]);

        $this->postJson("/api/v1/clients/{$client->id}/call-requests")->assertStatus(422);
        $this->assertSame(0, CallRequest::count());
    }

    public function test_an_invisible_client_reads_as_absent(): void
    {
        Notification::fake();
        $this->actingAgent();
        $client = Client::factory()->create(); // someone else's client

        $this->postJson("/api/v1/clients/{$client->id}/call-requests")->assertNotFound();
    }

    public function test_dialing_prompts_the_log_reminder_once(): void
    {
        Notification::fake();
        $agent = $this->actingAgent();
        $client = Client::factory()->create(['assigned_agent_id' => $agent->id]);
        $callRequest = CallRequest::create([
            'user_id' => $agent->id, 'client_id' => $client->id,
            'phone' => $client->phone, 'status' => CallRequestStatus::Sent,
        ]);

        $this->postJson("/api/v1/call-requests/{$callRequest->id}/dialed")
            ->assertOk()
            ->assertJsonPath('data.status', 'dialed');

        // A double tap must not stack a second reminder.
        $this->postJson("/api/v1/call-requests/{$callRequest->id}/dialed")->assertOk();

        Notification::assertSentToTimes($agent, DomainNotification::class, 1);
        Notification::assertSentTo($agent, DomainNotification::class, fn ($n) => $n->kind === 'call_log_prompt'
            && $n->subjectId === $callRequest->id
            && $n->link === $callRequest->link());
    }

    public function test_someone_elses_request_cannot_be_advanced(): void
    {
        Notification::fake();
        $agent = $this->actingAgent();
        $client = Client::factory()->create(['assigned_agent_id' => $agent->id]);
        $other = User::factory()->create();
        $callRequest = CallRequest::create([
            'user_id' => $other->id, 'client_id' => $client->id,
            'phone' => $client->phone, 'status' => CallRequestStatus::Sent,
        ]);

        $this->postJson("/api/v1/call-requests/{$callRequest->id}/dialed")->assertForbidden();
        $this->postJson("/api/v1/call-requests/{$callRequest->id}/close", ['status' => 'logged'])
            ->assertForbidden();
    }

    public function test_closing_broadcasts_so_other_devices_drop_their_prompt(): void
    {
        Notification::fake();
        Event::fake([CallRequestClosed::class]);
        $agent = $this->actingAgent();
        $client = Client::factory()->create(['assigned_agent_id' => $agent->id]);
        $callRequest = CallRequest::create([
            'user_id' => $agent->id, 'client_id' => $client->id,
            'phone' => $client->phone, 'status' => CallRequestStatus::Dialed,
        ]);

        $this->postJson("/api/v1/call-requests/{$callRequest->id}/close", ['status' => 'logged'])
            ->assertOk()
            ->assertJsonPath('data.status', 'logged');

        Event::assertDispatched(CallRequestClosed::class, fn ($e) => $e->userId === $agent->id
            && $e->callRequestId === $callRequest->id
            && $e->status === 'logged');

        // Closing an already-closed request is a no-op, not a second broadcast.
        $this->postJson("/api/v1/call-requests/{$callRequest->id}/close", ['status' => 'dismissed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'logged');
        Event::assertDispatchedTimes(CallRequestClosed::class, 1);
    }
}
