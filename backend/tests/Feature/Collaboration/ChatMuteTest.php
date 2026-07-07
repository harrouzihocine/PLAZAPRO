<?php

declare(strict_types=1);

namespace Tests\Feature\Collaboration;

use App\Modules\Collaboration\Models\Conversation;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChatMuteTest extends TestCase
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

    public function test_a_participant_toggles_their_own_mute_flag(): void
    {
        $me = $this->userWith();
        $other = $this->userWith();
        $conversation = $this->directBetween($me, $other);

        Sanctum::actingAs($me);
        $this->postJson("/api/v1/conversations/{$conversation->id}/mute")
            ->assertOk()
            ->assertJsonPath('muted', true);

        $this->getJson('/api/v1/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.is_muted', true);

        $this->postJson("/api/v1/conversations/{$conversation->id}/mute")
            ->assertOk()
            ->assertJsonPath('muted', false);
    }

    public function test_muting_only_silences_the_caller_not_the_other_side(): void
    {
        $me = $this->userWith();
        $other = $this->userWith();
        $conversation = $this->directBetween($me, $other);

        Sanctum::actingAs($me);
        $this->postJson("/api/v1/conversations/{$conversation->id}/mute")->assertOk();

        Sanctum::actingAs($other);
        $this->getJson('/api/v1/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.is_muted', false);
    }

    public function test_a_muted_participant_gets_no_chat_notification(): void
    {
        $me = $this->userWith();
        $other = $this->userWith(['chat.use', 'notifications.view']);
        $conversation = $this->directBetween($me, $other);

        Sanctum::actingAs($other);
        $this->postJson("/api/v1/conversations/{$conversation->id}/mute")->assertOk();

        Sanctum::actingAs($me);
        $this->postJson("/api/v1/conversations/{$conversation->id}/messages", ['body' => 'ping'])
            ->assertCreated();

        // The existing NotifyParticipantsOfMessage listener skips muted pivots.
        $this->assertSame(0, $other->notifications()->count());
    }

    public function test_a_non_participant_cannot_mute(): void
    {
        $me = $this->userWith();
        $other = $this->userWith();
        $stranger = $this->userWith();
        $conversation = $this->directBetween($me, $other);

        Sanctum::actingAs($stranger);
        $this->postJson("/api/v1/conversations/{$conversation->id}/mute")->assertForbidden();
    }
}
