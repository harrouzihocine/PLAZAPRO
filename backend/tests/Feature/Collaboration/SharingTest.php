<?php

declare(strict_types=1);

namespace Tests\Feature\Collaboration;

use App\Modules\Collaboration\Models\Conversation;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SharingTest extends TestCase
{
    use RefreshDatabase;

    /** @param  list<string>  $slugs */
    private function userWith(array $slugs): User
    {
        $role = Role::factory()->create();
        $ids = collect($slugs)->map(
            fn (string $slug) => Permission::firstOrCreate(['slug' => $slug], ['name' => $slug])->id
        );
        $role->permissions()->sync($ids);

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function group(User $admin, array $members = []): Conversation
    {
        $conversation = Conversation::factory()->group()->create(['created_by' => $admin->id]);
        $conversation->participants()->attach($admin->id, ['role' => 'admin', 'joined_at' => now()]);
        foreach ($members as $m) {
            $conversation->participants()->attach($m->id, ['role' => 'member', 'joined_at' => now()]);
        }

        return $conversation;
    }

    public function test_a_group_admin_can_add_a_participant(): void
    {
        $admin = $this->userWith(['chat.use']);
        $newbie = $this->userWith(['chat.use']);
        $group = $this->group($admin);

        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/conversations/{$group->id}/participants", ['user_ids' => [$newbie->id]])
            ->assertOk();

        $this->assertTrue($group->fresh()->hasParticipant($newbie));
    }

    public function test_a_non_admin_cannot_add_participants(): void
    {
        $admin = $this->userWith(['chat.use']);
        $member = $this->userWith(['chat.use']);
        $newbie = $this->userWith(['chat.use']);
        $group = $this->group($admin, [$member]);

        Sanctum::actingAs($member);
        $this->postJson("/api/v1/conversations/{$group->id}/participants", ['user_ids' => [$newbie->id]])
            ->assertForbidden();
    }

    public function test_a_member_can_leave_a_group(): void
    {
        $admin = $this->userWith(['chat.use']);
        $member = $this->userWith(['chat.use']);
        $group = $this->group($admin, [$member]);

        Sanctum::actingAs($member);
        $this->deleteJson("/api/v1/conversations/{$group->id}/participants/{$member->id}")->assertOk();

        $this->assertFalse($group->fresh()->hasParticipant($member));
    }

    public function test_sharing_a_record_creates_a_system_message_with_a_subject(): void
    {
        $sharer = $this->userWith(['chat.use', 'units.view']);
        $group = $this->group($sharer);
        $unit = Unit::factory()->create();

        Sanctum::actingAs($sharer);
        $this->postJson("/api/v1/conversations/{$group->id}/share", [
            'subject_type' => 'unit',
            'subject_id' => $unit->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'system')
            ->assertJsonPath('data.subject.restricted', false)
            ->assertJsonPath('data.subject.type', 'unit');

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $group->id,
            'subject_type' => 'unit',
            'subject_id' => $unit->id,
            'type' => 'system',
        ]);
    }

    public function test_you_cannot_share_a_record_you_cannot_view(): void
    {
        $sharer = $this->userWith(['chat.use']); // no units.view
        $group = $this->group($sharer);
        $unit = Unit::factory()->create();

        Sanctum::actingAs($sharer);
        $this->postJson("/api/v1/conversations/{$group->id}/share", [
            'subject_type' => 'unit',
            'subject_id' => $unit->id,
        ])->assertForbidden();
    }

    public function test_a_shared_record_is_only_visible_to_participants_whose_rbac_permits_it(): void
    {
        $sharer = $this->userWith(['chat.use', 'units.view']);
        $canView = $this->userWith(['chat.use', 'units.view']);
        $cannotView = $this->userWith(['chat.use']); // no units.view
        $group = $this->group($sharer, [$canView, $cannotView]);
        $unit = Unit::factory()->create();

        Sanctum::actingAs($sharer);
        $this->postJson("/api/v1/conversations/{$group->id}/share", [
            'subject_type' => 'unit',
            'subject_id' => $unit->id,
        ])->assertCreated();

        // The permitted participant sees the card (label + link).
        Sanctum::actingAs($canView);
        $this->getJson("/api/v1/conversations/{$group->id}/messages")
            ->assertOk()
            ->assertJsonPath('data.0.subject.restricted', false)
            ->assertJsonPath('data.0.subject.label', 'Unit '.$unit->reference);

        // The participant without units.view sees only that something was shared.
        Sanctum::actingAs($cannotView);
        $this->getJson("/api/v1/conversations/{$group->id}/messages")
            ->assertOk()
            ->assertJsonPath('data.0.subject.restricted', true)
            ->assertJsonMissingPath('data.0.subject.label');
    }
}
