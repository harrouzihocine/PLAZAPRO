<?php

declare(strict_types=1);

namespace Tests\Feature\Collaboration;

use App\Modules\Collaboration\Models\Conversation;
use App\Modules\Collaboration\Models\Message;
use App\Modules\Collaboration\Models\MessageAttachment;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChatTest extends TestCase
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

    public function test_chat_requires_chat_use(): void
    {
        Sanctum::actingAs($this->userWith(['clients.view']));

        $this->getJson('/api/v1/conversations')->assertForbidden();
    }

    public function test_contacts_lists_other_active_users(): void
    {
        $me = $this->userWith();
        $other = $this->userWith();
        Sanctum::actingAs($me);

        $this->getJson('/api/v1/chat/contacts')
            ->assertOk()
            ->assertJsonPath('data.0.id', $other->id)
            ->assertJsonMissing(['id' => $me->id]);
    }

    public function test_a_user_only_sees_conversations_they_participate_in(): void
    {
        $me = $this->userWith();
        $other = $this->userWith();
        $stranger = $this->userWith();

        $mine = $this->directBetween($me, $other);
        $this->directBetween($other, $stranger); // not mine

        Sanctum::actingAs($me);

        $this->getJson('/api/v1/conversations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);
    }

    public function test_creating_a_direct_conversation_is_deduped(): void
    {
        $me = $this->userWith();
        $other = $this->userWith();
        Sanctum::actingAs($me);

        $first = $this->postJson('/api/v1/conversations', [
            'type' => 'direct',
            'participant_ids' => [$other->id],
        ])->assertCreated()->json('data.id');

        // Reopening the same 1:1 returns the existing thread (200, nothing created).
        $second = $this->postJson('/api/v1/conversations', [
            'type' => 'direct',
            'participant_ids' => [$other->id],
        ])->assertOk()->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, Conversation::count());
    }

    public function test_sending_a_message_updates_last_message_at_and_others_unread(): void
    {
        $me = $this->userWith();
        $other = $this->userWith();
        $conversation = $this->directBetween($me, $other);

        Sanctum::actingAs($me);
        $this->postJson("/api/v1/conversations/{$conversation->id}/messages", ['body' => 'Hello'])
            ->assertCreated()
            ->assertJsonPath('data.body', 'Hello');

        $this->assertNotNull($conversation->fresh()->last_message_at);

        // The other participant now has one unread.
        Sanctum::actingAs($other);
        $this->getJson('/api/v1/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.unread_count', 1);

        // After marking read, the badge clears.
        $this->postJson("/api/v1/conversations/{$conversation->id}/read")->assertOk();
        $this->getJson('/api/v1/conversations')->assertJsonPath('data.0.unread_count', 0);
    }

    public function test_a_non_participant_cannot_read_or_send(): void
    {
        $me = $this->userWith();
        $other = $this->userWith();
        $stranger = $this->userWith();
        $conversation = $this->directBetween($me, $other);

        Sanctum::actingAs($stranger);
        $this->getJson("/api/v1/conversations/{$conversation->id}/messages")->assertForbidden();
        $this->postJson("/api/v1/conversations/{$conversation->id}/messages", ['body' => 'hi'])->assertForbidden();
    }

    public function test_sending_a_message_notifies_the_other_participant(): void
    {
        $me = $this->userWith();
        $other = $this->userWith(['chat.use', 'notifications.view']);
        $conversation = $this->directBetween($me, $other);

        Sanctum::actingAs($me);
        $this->postJson("/api/v1/conversations/{$conversation->id}/messages", ['body' => 'Ping'])->assertCreated();

        // First-ever contact from me → one bell row (chat_first_message).
        // Routine chat traffic itself never lands in the bell feed.
        $this->assertSame(1, $other->notifications()->count());
        $this->assertSame('chat_first_message', $other->notifications()->first()->data['kind']);
        // The author is not notified about their own message.
        $this->assertSame(0, $me->notifications()->count());

        // A second message adds no bell row — the dock / tray / tab badge own it.
        $this->postJson("/api/v1/conversations/{$conversation->id}/messages", ['body' => 'Again'])->assertCreated();
        $this->assertSame(1, $other->notifications()->count());
    }

    public function test_a_deleted_message_is_redacted_not_removed(): void
    {
        $me = $this->userWith();
        $other = $this->userWith();
        $conversation = $this->directBetween($me, $other);
        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $me->id,
            'body' => 'secret',
        ]);

        Sanctum::actingAs($me);
        $this->deleteJson("/api/v1/messages/{$message->id}")
            ->assertOk()
            ->assertJsonPath('data.redacted', true)
            ->assertJsonPath('data.body', null);

        // The row is kept (cancelled), never physically removed.
        $this->assertDatabaseHas('messages', ['id' => $message->id, 'status' => 'cancelled']);
    }

    public function test_only_the_author_or_a_group_admin_can_redact(): void
    {
        $me = $this->userWith();
        $other = $this->userWith();
        $conversation = $this->directBetween($me, $other);
        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $me->id,
        ]);

        // A participant who is neither author nor admin cannot redact.
        Sanctum::actingAs($other);
        $this->deleteJson("/api/v1/messages/{$message->id}")->assertForbidden();
    }

    public function test_a_voice_note_uploads_to_the_private_disk_with_a_uuid_name_and_duration(): void
    {
        Storage::fake('chat');
        $me = $this->userWith();
        $other = $this->userWith();
        $conversation = $this->directBetween($me, $other);

        Sanctum::actingAs($me);
        $file = UploadedFile::fake()->create('note.webm', 80, 'audio/webm');

        $this->post(
            "/api/v1/conversations/{$conversation->id}/messages",
            ['attachment' => $file, 'duration_ms' => 4200],
            ['Accept' => 'application/json'],
        )->assertCreated()->assertJsonPath('data.type', 'voice');

        $attachment = MessageAttachment::first();
        $this->assertSame('voice', $attachment->kind->value);
        $this->assertSame('chat', $attachment->disk);
        $this->assertSame(4200, $attachment->duration_ms);
        $this->assertMatchesRegularExpression('#attachments/\d{4}/\d{2}/[0-9a-f-]{36}#', $attachment->path);
        Storage::disk('chat')->assertExists($attachment->path);
        // Voice notes never enter the image-optimization pipeline.
        $this->assertNull($attachment->optimize_status);
    }

    public function test_an_image_attachment_is_queued_for_inplace_optimization(): void
    {
        Storage::fake('chat');
        Queue::fake();
        $me = $this->userWith();
        $other = $this->userWith();
        $conversation = $this->directBetween($me, $other);

        Sanctum::actingAs($me);
        $this->post(
            "/api/v1/conversations/{$conversation->id}/messages",
            ['attachment' => UploadedFile::fake()->image('plan.jpg')],
            ['Accept' => 'application/json'],
        )->assertCreated()->assertJsonPath('data.type', 'image');

        $this->assertSame('pending', MessageAttachment::first()->optimize_status);
        Queue::assertPushed(\App\Modules\Collaboration\Jobs\OptimizeAttachment::class);
    }

    public function test_attachment_streaming_is_participant_gated(): void
    {
        Storage::fake('chat');
        $me = $this->userWith();
        $other = $this->userWith();
        $stranger = $this->userWith();
        $conversation = $this->directBetween($me, $other);

        $message = Message::factory()->create(['conversation_id' => $conversation->id, 'user_id' => $me->id]);
        Storage::disk('chat')->put('attachments/x.jpg', 'bytes');
        $attachment = MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'path' => 'attachments/x.jpg',
        ]);

        Sanctum::actingAs($stranger);
        $this->get("/api/v1/attachments/{$attachment->id}")->assertForbidden();

        Sanctum::actingAs($me);
        $this->get("/api/v1/attachments/{$attachment->id}")->assertOk();
    }

    public function test_a_redacted_messages_attachment_is_not_streamable(): void
    {
        Storage::fake('chat');
        $me = $this->userWith();
        $other = $this->userWith();
        $conversation = $this->directBetween($me, $other);

        $message = Message::factory()->create(['conversation_id' => $conversation->id, 'user_id' => $me->id]);
        Storage::disk('chat')->put('attachments/y.jpg', 'bytes');
        $attachment = MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'path' => 'attachments/y.jpg',
        ]);
        $message->cancel('deleted');

        Sanctum::actingAs($me);
        $this->get("/api/v1/attachments/{$attachment->id}")->assertNotFound();
    }
}
