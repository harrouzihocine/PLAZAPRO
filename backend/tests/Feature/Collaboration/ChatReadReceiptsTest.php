<?php

declare(strict_types=1);

namespace Tests\Feature\Collaboration;

use App\Modules\Collaboration\Events\ConversationRead;
use App\Modules\Collaboration\Models\Conversation;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChatReadReceiptsTest extends TestCase
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

    public function test_participants_expose_their_read_cursor(): void
    {
        $me = $this->userWith();
        $other = $this->userWith();
        $conversation = $this->directBetween($me, $other);

        Sanctum::actingAs($other);
        $this->postJson("/api/v1/conversations/{$conversation->id}/read")->assertOk();

        Sanctum::actingAs($me);
        $participants = $this->getJson('/api/v1/conversations')
            ->assertOk()
            ->json('data.0.participants');

        $theirs = collect($participants)->firstWhere('id', $other->id);
        $this->assertNotNull($theirs['last_read_at'], 'the other participant read — their cursor must be exposed');
        $this->assertArrayHasKey('last_read_at', collect($participants)->firstWhere('id', $me->id));
    }

    public function test_marking_read_broadcasts_the_cursor_to_the_thread(): void
    {
        Event::fake([ConversationRead::class]);

        $me = $this->userWith();
        $other = $this->userWith();
        $conversation = $this->directBetween($me, $other);

        Sanctum::actingAs($me);
        $this->postJson("/api/v1/conversations/{$conversation->id}/read")->assertOk();

        Event::assertDispatched(ConversationRead::class, fn (ConversationRead $e) => $e->conversationId === $conversation->id && $e->userId === $me->id);
    }

    public function test_a_non_participant_overseer_read_stays_silent(): void
    {
        Event::fake([ConversationRead::class]);

        $me = $this->userWith();
        $other = $this->userWith();
        $conversation = $this->directBetween($me, $other);
        $conversation->forceFill(['type' => 'project'])->saveQuietly();

        $overseer = $this->userWith(['chat.use', 'chat.view_project_chats']);
        Sanctum::actingAs($overseer);
        $this->postJson("/api/v1/conversations/{$conversation->id}/read")->assertOk();

        // No pivot row updated → no broadcast → overseers never appear as "seen".
        Event::assertNotDispatched(ConversationRead::class);
    }
}
