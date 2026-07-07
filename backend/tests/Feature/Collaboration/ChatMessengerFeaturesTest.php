<?php

declare(strict_types=1);

namespace Tests\Feature\Collaboration;

use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Collaboration\Models\Conversation;
use App\Modules\Collaboration\Models\DeviceToken;
use App\Modules\Collaboration\Models\Message;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Messenger-parity slice: per-user conversation delete (hide + clear,
 * resurrect on a new message), in-place message edit, forwarding, and the FCM
 * device-token registry behind system-tray push.
 */
class ChatMessengerFeaturesTest extends TestCase
{
    use RefreshDatabase;

    /** @param  list<string>  $slugs */
    private function userWith(array $slugs = ['chat.use']): User
    {
        $role = Role::factory()->create();
        $ids = collect($slugs)->map(
            fn (string $slug) => Permission::firstOrCreate(['slug' => $slug], ['name' => $slug])->id
        );
        $role->permissions()->sync($ids);

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function directBetween(User $a, User $b): Conversation
    {
        $conversation = Conversation::factory()->create(['created_by' => $a->id]);
        $conversation->participants()->attach([
            $a->id => ['role' => 'member', 'joined_at' => now()],
            $b->id => ['role' => 'member', 'joined_at' => now()],
        ]);

        return $conversation;
    }

    // ── Delete conversation (per-user hide + clear) ──

    public function test_deleting_a_direct_conversation_hides_it_for_me_only(): void
    {
        $me = $this->userWith();
        $other = $this->userWith();
        $conversation = $this->directBetween($me, $other);

        Sanctum::actingAs($me);
        $this->deleteJson("/api/v1/conversations/{$conversation->id}")->assertOk();
        $this->getJson('/api/v1/conversations')->assertOk()->assertJsonCount(0, 'data');

        // The other participant keeps the thread untouched.
        Sanctum::actingAs($other);
        $this->getJson('/api/v1/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.id', $conversation->id);
    }

    public function test_a_new_message_resurrects_the_thread_without_my_cleared_history(): void
    {
        $me = $this->userWith();
        $other = $this->userWith();
        $conversation = $this->directBetween($me, $other);

        Sanctum::actingAs($me);
        $this->postJson("/api/v1/conversations/{$conversation->id}/messages", ['body' => 'old history'])
            ->assertCreated();
        $this->deleteJson("/api/v1/conversations/{$conversation->id}")->assertOk();

        // The other side writes again — the thread comes back for me...
        Sanctum::actingAs($other);
        $this->travel(1)->minutes();
        $this->postJson("/api/v1/conversations/{$conversation->id}/messages", ['body' => 'hello again'])
            ->assertCreated();

        Sanctum::actingAs($me);
        $this->getJson('/api/v1/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.id', $conversation->id);

        // ...but my pre-delete history stays gone (Messenger semantics).
        $bodies = $this->getJson("/api/v1/conversations/{$conversation->id}/messages")
            ->assertOk()->json('data.*.body');
        $this->assertSame(['hello again'], $bodies);

        // The OTHER participant still sees the full history.
        Sanctum::actingAs($other);
        $this->assertCount(2, $this->getJson("/api/v1/conversations/{$conversation->id}/messages")->json('data'));
    }

    public function test_project_chats_cannot_be_deleted(): void
    {
        $me = $this->userWith();
        $client = Client::factory()->create();
        $project = ClientProject::factory()->create(['client_id' => $client->id]);
        $conversation = Conversation::factory()->create([
            'type' => 'project',
            'subject_type' => 'client_project',
            'subject_id' => $project->id,
            'created_by' => $me->id,
        ]);
        $conversation->participants()->attach([$me->id => ['role' => 'member', 'joined_at' => now()]]);

        Sanctum::actingAs($me);
        $this->deleteJson("/api/v1/conversations/{$conversation->id}")->assertStatus(422);
    }

    public function test_a_non_participant_cannot_delete_a_conversation(): void
    {
        $a = $this->userWith();
        $b = $this->userWith();
        $stranger = $this->userWith();
        $conversation = $this->directBetween($a, $b);

        Sanctum::actingAs($stranger);
        $this->deleteJson("/api/v1/conversations/{$conversation->id}")->assertForbidden();
    }

    // ── Edit message ──

    public function test_the_author_can_edit_their_own_text_message(): void
    {
        $me = $this->userWith();
        $other = $this->userWith();
        $conversation = $this->directBetween($me, $other);

        Sanctum::actingAs($me);
        $id = $this->postJson("/api/v1/conversations/{$conversation->id}/messages", ['body' => 'typo'])
            ->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/messages/{$id}", ['body' => 'fixed'])
            ->assertOk()
            ->assertJsonPath('data.body', 'fixed');

        $this->assertNotNull(Message::findOrFail($id)->edited_at);
    }

    public function test_only_the_author_can_edit_and_deleted_messages_cannot_be_edited(): void
    {
        $me = $this->userWith();
        $other = $this->userWith();
        $conversation = $this->directBetween($me, $other);

        Sanctum::actingAs($me);
        $id = $this->postJson("/api/v1/conversations/{$conversation->id}/messages", ['body' => 'mine'])
            ->assertCreated()->json('data.id');

        Sanctum::actingAs($other);
        $this->patchJson("/api/v1/messages/{$id}", ['body' => 'hijack'])->assertForbidden();

        Sanctum::actingAs($me);
        $this->deleteJson("/api/v1/messages/{$id}")->assertOk();
        $this->patchJson("/api/v1/messages/{$id}", ['body' => 'necromancy'])->assertStatus(422);
    }

    // ── Forward ──

    public function test_forwarding_copies_the_message_into_target_threads_with_provenance(): void
    {
        Storage::fake('chat');
        $me = $this->userWith();
        $friendA = $this->userWith();
        $friendB = $this->userWith();
        $source = $this->directBetween($me, $friendA);
        $target = $this->directBetween($me, $friendB);

        Sanctum::actingAs($me);
        $id = $this->postJson("/api/v1/conversations/{$source->id}/messages", [
            'body' => 'worth sharing',
            'attachment' => UploadedFile::fake()->image('plan.jpg'),
        ])->assertCreated()->json('data.id');

        $response = $this->postJson("/api/v1/messages/{$id}/forward", [
            'conversation_ids' => [$target->id],
        ])->assertOk();

        $copyId = $response->json('data.0.id');
        $copy = Message::with('attachments')->findOrFail($copyId);
        $this->assertSame($target->id, $copy->conversation_id);
        $this->assertSame('worth sharing', $copy->body);
        $this->assertSame($id, $copy->forwarded_from_id);
        $this->assertCount(1, $copy->attachments);

        // The copy renders the Forwarded tag for the target thread.
        Sanctum::actingAs($friendB);
        $this->getJson("/api/v1/conversations/{$target->id}/messages")
            ->assertOk()
            ->assertJsonPath('data.0.forwarded', true);
    }

    public function test_forwarding_into_a_thread_i_cannot_post_in_is_refused(): void
    {
        $me = $this->userWith();
        $friend = $this->userWith();
        $a = $this->userWith();
        $b = $this->userWith();
        $mine = $this->directBetween($me, $friend);
        $theirs = $this->directBetween($a, $b);

        Sanctum::actingAs($me);
        $id = $this->postJson("/api/v1/conversations/{$mine->id}/messages", ['body' => 'leak?'])
            ->assertCreated()->json('data.id');

        $this->postJson("/api/v1/messages/{$id}/forward", ['conversation_ids' => [$theirs->id]])
            ->assertForbidden();
    }

    // ── Device tokens (system-tray push registry) ──

    public function test_registering_a_device_token_claims_it_for_the_caller(): void
    {
        $me = $this->userWith();
        $other = $this->userWith();

        Sanctum::actingAs($me);
        $this->postJson('/api/v1/device-tokens', ['token' => 'fcm-abc', 'platform' => 'android'])
            ->assertOk();
        $this->assertSame($me->id, DeviceToken::firstWhere('token', 'fcm-abc')->user_id);

        // Another user logging in on the same device takes the row over.
        Sanctum::actingAs($other);
        $this->postJson('/api/v1/device-tokens', ['token' => 'fcm-abc'])->assertOk();
        $this->assertSame(1, DeviceToken::count());
        $this->assertSame($other->id, DeviceToken::firstWhere('token', 'fcm-abc')->user_id);
    }

    public function test_forgetting_releases_only_the_callers_token(): void
    {
        $me = $this->userWith();
        $other = $this->userWith();
        DeviceToken::create(['user_id' => $other->id, 'token' => 'fcm-xyz', 'platform' => 'android']);

        Sanctum::actingAs($me);
        $this->postJson('/api/v1/device-tokens/forget', ['token' => 'fcm-xyz'])->assertOk();
        $this->assertSame(1, DeviceToken::count()); // not mine — untouched

        Sanctum::actingAs($other);
        $this->postJson('/api/v1/device-tokens/forget', ['token' => 'fcm-xyz'])->assertOk();
        $this->assertSame(0, DeviceToken::count());
    }
}
