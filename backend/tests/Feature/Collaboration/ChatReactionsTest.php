<?php

declare(strict_types=1);

namespace Tests\Feature\Collaboration;

use App\Modules\Collaboration\Models\Conversation;
use App\Modules\Collaboration\Models\Message;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChatReactionsTest extends TestCase
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

    private function messageIn(Conversation $conversation, User $author, string $body = 'hello'): Message
    {
        return Message::factory()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $author->id,
            'body' => $body,
        ]);
    }

    public function test_a_participant_reacts_and_the_resource_groups_it_as_mine(): void
    {
        $me = $this->userWith();
        $other = $this->userWith();
        $conversation = $this->directBetween($me, $other);
        $message = $this->messageIn($conversation, $other);

        Sanctum::actingAs($me);
        $this->postJson("/api/v1/messages/{$message->id}/reactions", ['emoji' => '👍'])
            ->assertOk()
            ->assertJsonPath('data.reactions.0.emoji', '👍')
            ->assertJsonPath('data.reactions.0.count', 1)
            ->assertJsonPath('data.reactions.0.mine', true);
    }

    public function test_the_same_emoji_toggles_off_and_a_different_one_replaces(): void
    {
        $me = $this->userWith();
        $other = $this->userWith();
        $conversation = $this->directBetween($me, $other);
        $message = $this->messageIn($conversation, $other);

        Sanctum::actingAs($me);
        $this->postJson("/api/v1/messages/{$message->id}/reactions", ['emoji' => '👍'])->assertOk();

        // A different emoji replaces — never two reactions from one user.
        $this->postJson("/api/v1/messages/{$message->id}/reactions", ['emoji' => '❤️'])
            ->assertOk()
            ->assertJsonPath('data.reactions.0.emoji', '❤️')
            ->assertJsonCount(1, 'data.reactions');
        $this->assertSame(1, $message->reactions()->count());

        // The same emoji again toggles it off.
        $this->postJson("/api/v1/messages/{$message->id}/reactions", ['emoji' => '❤️'])
            ->assertOk()
            ->assertJsonCount(0, 'data.reactions');
        $this->assertSame(0, $message->reactions()->count());
    }

    public function test_an_emoji_outside_the_fixed_set_is_rejected(): void
    {
        $me = $this->userWith();
        $other = $this->userWith();
        $conversation = $this->directBetween($me, $other);
        $message = $this->messageIn($conversation, $other);

        Sanctum::actingAs($me);
        $this->postJson("/api/v1/messages/{$message->id}/reactions", ['emoji' => '🦄'])
            ->assertUnprocessable();
    }

    public function test_a_non_participant_cannot_react(): void
    {
        $me = $this->userWith();
        $other = $this->userWith();
        $stranger = $this->userWith();
        $conversation = $this->directBetween($me, $other);
        $message = $this->messageIn($conversation, $me);

        Sanctum::actingAs($stranger);
        $this->postJson("/api/v1/messages/{$message->id}/reactions", ['emoji' => '👍'])
            ->assertForbidden();
    }

    public function test_a_read_only_field_agent_observer_cannot_react(): void
    {
        $me = $this->userWith();
        $other = $this->userWith();
        $observer = $this->userWith();
        $conversation = $this->directBetween($me, $other);
        $conversation->participants()->attach([
            $observer->id => ['role' => Conversation::ROLE_FIELD_AGENT_OBSERVER, 'joined_at' => now()],
        ]);
        $message = $this->messageIn($conversation, $me);

        Sanctum::actingAs($observer);
        $this->postJson("/api/v1/messages/{$message->id}/reactions", ['emoji' => '👍'])
            ->assertForbidden();
    }

    public function test_a_redacted_message_cannot_be_reacted_to(): void
    {
        $me = $this->userWith();
        $other = $this->userWith();
        $conversation = $this->directBetween($me, $other);
        $message = $this->messageIn($conversation, $other);
        $message->cancel('deleted');

        Sanctum::actingAs($me);
        $this->postJson("/api/v1/messages/{$message->id}/reactions", ['emoji' => '👍'])
            ->assertUnprocessable();
    }
}
