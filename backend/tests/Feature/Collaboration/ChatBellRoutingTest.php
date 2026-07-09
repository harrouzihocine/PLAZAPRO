<?php

declare(strict_types=1);

namespace Tests\Feature\Collaboration;

use App\Modules\Collaboration\Events\ConversationRead;
use App\Modules\Collaboration\Events\MessageSent;
use App\Modules\Collaboration\Models\Conversation;
use App\Modules\Collaboration\Models\Message;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Chat's split personality in the notification system: routine messages stay
 * OUT of the bell feed (dock / tray / tab badge own them), only a sender's
 * first-ever message to a recipient lands a chat_first_message bell row — and
 * reading the thread anywhere retires those rows everywhere.
 */
class ChatBellRoutingTest extends TestCase
{
    use RefreshDatabase;

    /** @param  list<string>  $slugs */
    private function userWith(array $slugs = ['chat.use', 'notifications.view']): User
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

    public function test_a_group_post_bells_only_participants_the_sender_never_messaged(): void
    {
        $me = $this->userWith();
        $known = $this->userWith();   // already got a direct message from me
        $stranger = $this->userWith(); // never heard from me

        $direct = $this->directBetween($me, $known);
        Message::factory()->create([
            'conversation_id' => $direct->id,
            'user_id' => $me->id,
            'body' => 'old hello',
        ]);

        $group = Conversation::factory()->group('Deal room')->create(['created_by' => $me->id]);
        $group->participants()->attach([
            $me->id => ['role' => 'admin', 'joined_at' => now()],
            $known->id => ['role' => 'member', 'joined_at' => now()],
            $stranger->id => ['role' => 'member', 'joined_at' => now()],
        ]);

        Sanctum::actingAs($me);
        $this->postJson("/api/v1/conversations/{$group->id}/messages", ['body' => 'kickoff'])
            ->assertCreated();

        // The stranger gets the one first-contact bell row; the known contact
        // gets none — their chat surfaces (dock/tray/badge) already carry it.
        $this->assertSame(1, $stranger->notifications()->count());
        $this->assertSame('chat_first_message', $stranger->notifications()->first()->data['kind']);
        $this->assertSame(0, $known->notifications()->count());
    }

    public function test_no_first_contact_bell_when_the_recipient_already_read_the_message(): void
    {
        $me = $this->userWith();
        $other = $this->userWith();
        $conversation = $this->directBetween($me, $other);

        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $me->id,
            'created_at' => now()->subMinute(),
        ]);
        // The notify job runs on the queue — by then a recipient with the
        // thread open may have read the message already. A bell row written
        // now would be born unread with nothing left to retire it.
        $conversation->participants()->updateExistingPivot($other->id, ['last_read_at' => now()]);

        MessageSent::dispatch($message->load(['conversation.participants', 'author']));

        $this->assertSame(0, $other->notifications()->count());
    }

    public function test_reading_a_conversation_retires_its_bell_rows_and_tells_my_other_devices(): void
    {
        $me = $this->userWith();
        $other = $this->userWith();
        $conversation = $this->directBetween($me, $other);

        Sanctum::actingAs($me);
        $this->postJson("/api/v1/conversations/{$conversation->id}/messages", ['body' => 'Ping'])
            ->assertCreated();

        $this->assertSame(1, $other->unreadNotifications()->count());

        // Tapping the phone tray / a dock head / the bell entry all end here:
        // the thread is opened and its read cursor posted.
        Event::fake([ConversationRead::class]);
        Sanctum::actingAs($other);
        $this->postJson("/api/v1/conversations/{$conversation->id}/read")->assertOk();

        // The first-contact bell row is read now — on every device.
        $this->assertSame(0, $other->unreadNotifications()->count());
        $this->assertNotNull($other->notifications()->first()->read_at);

        // And the read broadcasts to the reader's own channel (badge sync on
        // their other devices) as well as the conversation channel (✓✓ ticks).
        Event::assertDispatched(ConversationRead::class, function (ConversationRead $e) use ($conversation, $other) {
            $channels = array_map('strval', $e->broadcastOn());

            return $e->conversationId === $conversation->id
                && $e->userId === $other->id
                && in_array('private-conversation.'.$conversation->id, $channels, true)
                && in_array('private-users.'.$other->id, $channels, true);
        });
    }
}
