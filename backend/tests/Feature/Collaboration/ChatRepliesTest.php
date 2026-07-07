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

class ChatRepliesTest extends TestCase
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

    public function test_a_reply_carries_the_quoted_block(): void
    {
        $me = $this->userWith();
        $other = $this->userWith();
        $conversation = $this->directBetween($me, $other);
        $target = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $other->id,
            'body' => 'Original question about the F3 in Hydra',
        ]);

        Sanctum::actingAs($me);
        $this->postJson("/api/v1/conversations/{$conversation->id}/messages", [
            'body' => 'Answering you',
            'reply_to_id' => $target->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.reply_to.id', $target->id)
            ->assertJsonPath('data.reply_to.author_name', $other->name)
            ->assertJsonPath('data.reply_to.excerpt', 'Original question about the F3 in Hydra')
            ->assertJsonPath('data.reply_to.redacted', false);

        // The thread listing renders the quote too (replyTo eager-loaded).
        $this->getJson("/api/v1/conversations/{$conversation->id}/messages")
            ->assertOk()
            ->assertJsonPath('data.1.reply_to.id', $target->id);
    }

    public function test_replying_to_a_message_from_another_conversation_is_rejected(): void
    {
        $me = $this->userWith();
        $other = $this->userWith();
        $third = $this->userWith();
        $mine = $this->directBetween($me, $other);
        $foreign = $this->directBetween($me, $third);
        $elsewhere = Message::factory()->create([
            'conversation_id' => $foreign->id,
            'user_id' => $third->id,
            'body' => 'other thread',
        ]);

        Sanctum::actingAs($me);
        $this->postJson("/api/v1/conversations/{$mine->id}/messages", [
            'body' => 'cross-link attempt',
            'reply_to_id' => $elsewhere->id,
        ])->assertUnprocessable();
    }

    public function test_a_quote_of_a_redacted_message_withholds_the_excerpt(): void
    {
        $me = $this->userWith();
        $other = $this->userWith();
        $conversation = $this->directBetween($me, $other);
        $target = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $other->id,
            'body' => 'secret',
        ]);

        Sanctum::actingAs($me);
        $reply = $this->postJson("/api/v1/conversations/{$conversation->id}/messages", [
            'body' => 'quoting',
            'reply_to_id' => $target->id,
        ])->assertCreated()->json('data.id');

        $target->cancel('deleted');

        $this->getJson("/api/v1/conversations/{$conversation->id}/messages")
            ->assertOk()
            ->assertJsonPath('data.1.id', $reply)
            ->assertJsonPath('data.1.reply_to.redacted', true)
            ->assertJsonPath('data.1.reply_to.excerpt', null);
    }
}
