<?php

declare(strict_types=1);

namespace Tests\Feature\Collaboration;

use App\Modules\Collaboration\Models\Broadcast;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BroadcastTest extends TestCase
{
    use RefreshDatabase;

    /** @param  list<string>  $slugs */
    private function userWithPermissions(array $slugs, array $attributes = []): User
    {
        $role = Role::factory()->create();
        $ids = collect($slugs)->map(
            fn (string $slug) => Permission::firstOrCreate(['slug' => $slug], ['name' => $slug])->id
        );
        $role->permissions()->sync($ids);

        return User::factory()->create([...$attributes, 'role_id' => $role->id]);
    }

    private function broadcaster(): User
    {
        return $this->userWithPermissions(['notifications.broadcast']);
    }

    public function test_sending_requires_the_broadcast_permission(): void
    {
        Sanctum::actingAs($this->userWithPermissions(['notifications.view']));

        $this->postJson('/api/v1/broadcasts', [
            'audience_type' => 'all',
            'body' => ['en' => 'Hello team'],
        ])->assertForbidden();
    }

    public function test_the_history_requires_the_broadcast_permission(): void
    {
        Sanctum::actingAs($this->userWithPermissions(['notifications.view']));

        $this->getJson('/api/v1/broadcasts')->assertForbidden();
    }

    public function test_at_least_one_language_must_be_filled(): void
    {
        Sanctum::actingAs($this->broadcaster());

        $this->postJson('/api/v1/broadcasts', [
            'audience_type' => 'all',
            'body' => ['en' => '  ', 'fr' => '', 'ar' => ''],
        ])->assertStatus(422)->assertJsonValidationErrors('body');
    }

    public function test_sending_to_users_records_the_broadcast_and_fans_out_notifications(): void
    {
        $sender = $this->broadcaster();
        $a = User::factory()->create();
        $b = User::factory()->create();
        $notTargeted = User::factory()->create();

        Sanctum::actingAs($sender);

        $this->postJson('/api/v1/broadcasts', [
            'audience_type' => 'users',
            'user_ids' => [$a->id, $b->id],
            'body' => ['en' => 'Office closed Friday'],
        ])->assertCreated()
            ->assertJsonPath('data.audience_type', 'users')
            ->assertJsonPath('data.recipient_count', 2)
            ->assertJsonPath('data.read_count', 0);

        $broadcast = Broadcast::first();
        $this->assertSame(2, $broadcast->recipients()->count());

        // The two targets each get one bell notification; nobody else does.
        $this->assertSame(1, $a->notifications()->count());
        $this->assertSame(1, $b->notifications()->count());
        $this->assertSame(0, $notTargeted->notifications()->count());
        $this->assertSame('broadcast', $a->notifications()->first()->data['kind']);
        $this->assertSame($broadcast->id, $a->notifications()->first()->data['subject_id']);
    }

    public function test_each_recipient_reads_the_message_in_their_own_language_with_fallback(): void
    {
        $sender = $this->broadcaster();
        $fr = User::factory()->create(['locale' => 'fr']);
        $ar = User::factory()->create(['locale' => 'ar']); // no Arabic text → falls back
        $en = User::factory()->create(['locale' => 'en']);

        Sanctum::actingAs($sender);

        $this->postJson('/api/v1/broadcasts', [
            'audience_type' => 'users',
            'user_ids' => [$fr->id, $ar->id, $en->id],
            'body' => ['en' => 'Team meeting at 3pm', 'fr' => 'Réunion à 15h'],
        ])->assertCreated();

        // Body is stored per recipient in their own language at send time.
        $this->assertSame('Réunion à 15h', $fr->notifications()->first()->data['body']);
        $this->assertSame('Team meeting at 3pm', $en->notifications()->first()->data['body']);
        $this->assertSame('Team meeting at 3pm', $ar->notifications()->first()->data['body']); // fallback

        // The shared title is localized per recipient too.
        $this->assertSame('Annonce', $fr->notifications()->first()->data['title']);
        $this->assertSame('Announcement', $en->notifications()->first()->data['title']);
    }

    public function test_a_role_audience_targets_that_roles_active_members(): void
    {
        $sender = $this->broadcaster();
        $role = Role::factory()->create();
        $member1 = User::factory()->create(['role_id' => $role->id]);
        $member2 = User::factory()->create(['role_id' => $role->id]);
        $inactive = User::factory()->create(['role_id' => $role->id, 'is_active' => false]);
        $outsider = User::factory()->create();

        Sanctum::actingAs($sender);

        $this->postJson('/api/v1/broadcasts', [
            'audience_type' => 'role',
            'role_id' => $role->id,
            'body' => ['en' => 'Sales huddle'],
        ])->assertCreated()->assertJsonPath('data.recipient_count', 2);

        $this->assertSame(1, $member1->notifications()->count());
        $this->assertSame(1, $member2->notifications()->count());
        $this->assertSame(0, $inactive->notifications()->count());
        $this->assertSame(0, $outsider->notifications()->count());
    }

    public function test_an_everyone_audience_targets_every_active_user(): void
    {
        $sender = $this->broadcaster();
        User::factory()->count(3)->create();
        User::factory()->create(['is_active' => false]);

        Sanctum::actingAs($sender);

        // 3 fresh users + the sender = 4 active recipients.
        $this->postJson('/api/v1/broadcasts', [
            'audience_type' => 'all',
            'body' => ['en' => 'All hands'],
        ])->assertCreated()->assertJsonPath('data.recipient_count', 4);
    }

    public function test_the_history_lists_broadcasts_with_read_counts(): void
    {
        $sender = $this->broadcaster();
        $a = User::factory()->create();
        $b = User::factory()->create();

        Sanctum::actingAs($sender);
        $this->postJson('/api/v1/broadcasts', [
            'audience_type' => 'users',
            'user_ids' => [$a->id, $b->id],
            'body' => ['en' => 'Read me'],
        ])->assertCreated();

        // One recipient opens it (marks the bell notification read).
        $a->notifications()->first()->markAsRead();

        $this->getJson('/api/v1/broadcasts')
            ->assertOk()
            ->assertJsonPath('data.0.recipient_count', 2)
            ->assertJsonPath('data.0.read_count', 1)
            ->assertJsonPath('data.0.sender_name', $sender->name);
    }

    public function test_the_detail_reports_who_has_read_it_for_a_broadcaster(): void
    {
        $sender = $this->broadcaster();
        $a = User::factory()->create();
        $b = User::factory()->create();

        Sanctum::actingAs($sender);
        $this->postJson('/api/v1/broadcasts', [
            'audience_type' => 'users',
            'user_ids' => [$a->id, $b->id],
            'body' => ['en' => 'Who read this'],
        ])->assertCreated();
        $broadcast = Broadcast::first();

        $a->notifications()->first()->markAsRead();

        $res = $this->getJson("/api/v1/broadcasts/{$broadcast->id}")
            ->assertOk()
            ->assertJsonPath('data.delivered', 2)
            ->assertJsonPath('data.read_count', 1)
            ->assertJsonCount(2, 'data.recipients');

        $reader = collect($res->json('data.recipients'))->firstWhere('id', $a->id);
        $unread = collect($res->json('data.recipients'))->firstWhere('id', $b->id);
        $this->assertNotNull($reader['read_at']);
        $this->assertNull($unread['read_at']);
        // Broadcasters see every language they wrote.
        $this->assertArrayHasKey('body_translations', $res->json('data'));
    }

    public function test_a_recipient_can_open_the_broadcast_without_the_who_read_list(): void
    {
        $sender = $this->broadcaster();
        $recipient = $this->userWithPermissions(['notifications.view'], ['locale' => 'fr']);

        Sanctum::actingAs($sender);
        $this->postJson('/api/v1/broadcasts', [
            'audience_type' => 'users',
            'user_ids' => [$recipient->id],
            'body' => ['en' => 'Hello', 'fr' => 'Bonjour'],
        ])->assertCreated();
        $broadcast = Broadcast::first();

        Sanctum::actingAs($recipient);
        $this->getJson("/api/v1/broadcasts/{$broadcast->id}")
            ->assertOk()
            ->assertJsonPath('data.body', 'Bonjour') // their own language
            ->assertJsonMissingPath('data.recipients')
            ->assertJsonMissingPath('data.body_translations');
    }

    public function test_a_stranger_cannot_open_a_broadcast_they_did_not_receive(): void
    {
        $sender = $this->broadcaster();
        $recipient = User::factory()->create();
        $stranger = $this->userWithPermissions(['notifications.view']);

        Sanctum::actingAs($sender);
        $this->postJson('/api/v1/broadcasts', [
            'audience_type' => 'users',
            'user_ids' => [$recipient->id],
            'body' => ['en' => 'Private-ish'],
        ])->assertCreated();
        $broadcast = Broadcast::first();

        Sanctum::actingAs($stranger);
        $this->getJson("/api/v1/broadcasts/{$broadcast->id}")->assertForbidden();
    }
}
