<?php

declare(strict_types=1);

namespace Tests\Feature\Collaboration;

use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Collaboration\Models\Conversation;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The per-project dedicated chat: auto-created with the project, participants
 * mirror the contributors (creator + non-hidden viewers), backfillable for
 * projects that pre-date the feature.
 */
class ProjectChatTest extends TestCase
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

    private function projectChat(ClientProject $project): ?Conversation
    {
        return Conversation::query()
            ->where('type', 'project')
            ->where('subject_type', 'client_project')
            ->where('subject_id', $project->id)
            ->first();
    }

    public function test_creating_a_project_creates_its_dedicated_chat(): void
    {
        $creator = $this->userWith(['clients.view', 'clients.view_all', 'clients.create', 'projects.create', 'chat.use']);
        $client = Client::factory()->create();
        Sanctum::actingAs($creator);

        $response = $this->postJson("/api/v1/clients/{$client->id}/projects", [])
            ->assertCreated();

        $chat = $this->projectChat(ClientProject::findOrFail($response->json('data.id')));

        $this->assertNotNull($chat);
        $this->assertTrue($chat->hasParticipant($creator));
        $this->assertTrue($chat->isAdmin($creator));
    }

    public function test_viewer_changes_sync_the_chat_participants(): void
    {
        $creator = $this->userWith(['clients.view', 'clients.view_all', 'clients.create', 'projects.create', 'projects.contributors', 'chat.use']);
        $viewer = $this->userWith(['chat.use']);
        $client = Client::factory()->create();
        Sanctum::actingAs($creator);

        $projectId = $this->postJson("/api/v1/clients/{$client->id}/projects", [])->json('data.id');
        $project = ClientProject::findOrFail($projectId);

        // Added to the visibility list → joins the chat.
        $this->postJson("/api/v1/projects/{$projectId}/viewers", ['user_id' => $viewer->id])->assertOk();
        $this->assertTrue($this->projectChat($project)->hasParticipant($viewer));

        // Hidden from the list → leaves the chat.
        $this->postJson("/api/v1/projects/{$projectId}/viewers/{$viewer->id}/hide")->assertOk();
        $this->assertFalse($this->projectChat($project)->fresh()->hasParticipant($viewer));
    }

    public function test_the_project_conversation_endpoint_is_visibility_scoped(): void
    {
        $creator = $this->userWith(['clients.view', 'clients.view_all', 'clients.create', 'projects.create', 'chat.use']);
        $outsider = $this->userWith(['chat.use']);
        $client = Client::factory()->create();

        Sanctum::actingAs($creator);
        $projectId = $this->postJson("/api/v1/clients/{$client->id}/projects", [])->json('data.id');

        $this->getJson("/api/v1/projects/{$projectId}/conversation")
            ->assertOk()
            ->assertJsonPath('data.type', 'project');

        Sanctum::actingAs($outsider);
        $this->getJson("/api/v1/projects/{$projectId}/conversation")->assertNotFound();
    }

    public function test_oversight_permission_reads_project_chats_but_cannot_write(): void
    {
        $creator = $this->userWith(['clients.view', 'clients.view_all', 'clients.create', 'projects.create', 'chat.use']);
        $overseer = $this->userWith(['chat.use', 'chat.view_project_chats']);
        $client = Client::factory()->create();

        Sanctum::actingAs($creator);
        $projectId = $this->postJson("/api/v1/clients/{$client->id}/projects", [])->json('data.id');
        $chat = $this->projectChat(ClientProject::findOrFail($projectId));
        $this->postJson("/api/v1/conversations/{$chat->id}/messages", ['body' => 'internal note'])->assertCreated();

        // The overseer is NOT a participant, yet may read: the thread, its
        // messages, the read-cursor ping — but never write.
        Sanctum::actingAs($overseer);
        $this->getJson("/api/v1/conversations/{$chat->id}")
            ->assertOk()
            ->assertJsonPath('data.can_post', false);
        $this->getJson("/api/v1/conversations/{$chat->id}/messages")
            ->assertOk()
            ->assertJsonPath('data.0.body', 'internal note');
        $this->postJson("/api/v1/conversations/{$chat->id}/read")->assertOk();
        $this->postJson("/api/v1/conversations/{$chat->id}/messages", ['body' => 'butting in'])
            ->assertForbidden();

        // A participant still sees can_post = true.
        Sanctum::actingAs($creator);
        $this->getJson("/api/v1/conversations/{$chat->id}")
            ->assertOk()
            ->assertJsonPath('data.can_post', true);
    }

    public function test_participate_permission_reads_and_writes_project_chats(): void
    {
        $creator = $this->userWith(['clients.view', 'clients.view_all', 'clients.create', 'projects.create', 'chat.use']);
        $participant = $this->userWith(['chat.use', 'chat.participate_project_chats']);
        $client = Client::factory()->create();

        Sanctum::actingAs($creator);
        $projectId = $this->postJson("/api/v1/clients/{$client->id}/projects", [])->json('data.id');
        $chat = $this->projectChat(ClientProject::findOrFail($projectId));

        // Not a contributor, yet a full chat member by permission: reads AND writes.
        Sanctum::actingAs($participant);
        $this->getJson("/api/v1/conversations/{$chat->id}")
            ->assertOk()
            ->assertJsonPath('data.can_post', true);
        $this->postJson("/api/v1/conversations/{$chat->id}/messages", ['body' => 'joining the thread'])
            ->assertCreated()
            ->assertJsonPath('data.body', 'joining the thread');
    }

    public function test_oversight_permissions_do_not_open_direct_or_group_chats(): void
    {
        $overseer = $this->userWith(['chat.use', 'chat.view_project_chats', 'chat.participate_project_chats']);
        $a = $this->userWith(['chat.use']);
        $b = $this->userWith(['chat.use']);

        $direct = Conversation::factory()->create(['type' => 'direct', 'created_by' => $a->id]);
        $direct->participants()->attach([
            $a->id => ['role' => 'member', 'joined_at' => now()],
            $b->id => ['role' => 'member', 'joined_at' => now()],
        ]);

        Sanctum::actingAs($overseer);
        $this->getJson("/api/v1/conversations/{$direct->id}/messages")->assertForbidden();
        $this->getJson("/api/v1/conversations/{$direct->id}")->assertForbidden();
        $this->postJson("/api/v1/conversations/{$direct->id}/messages", ['body' => 'intruding'])
            ->assertForbidden();
    }

    public function test_without_the_permission_a_non_participant_cannot_read_a_project_chat(): void
    {
        $creator = $this->userWith(['clients.view', 'clients.view_all', 'clients.create', 'projects.create', 'chat.use']);
        $stranger = $this->userWith(['chat.use']);
        $client = Client::factory()->create();

        Sanctum::actingAs($creator);
        $projectId = $this->postJson("/api/v1/clients/{$client->id}/projects", [])->json('data.id');
        $chat = $this->projectChat(ClientProject::findOrFail($projectId));

        Sanctum::actingAs($stranger);
        $this->getJson("/api/v1/conversations/{$chat->id}/messages")->assertForbidden();
    }

    public function test_project_threads_cannot_be_created_by_hand(): void
    {
        $me = $this->userWith(['chat.use']);
        $other = $this->userWith(['chat.use']);
        Sanctum::actingAs($me);

        $this->postJson('/api/v1/conversations', [
            'type' => 'project',
            'participant_ids' => [$other->id],
        ])->assertStatus(422)->assertJsonValidationErrorFor('type');
    }

    public function test_backfill_command_creates_chats_for_existing_active_projects(): void
    {
        $creator = User::factory()->create();
        $project = ClientProject::factory()->create(['created_by' => $creator->id]);
        $this->assertNull($this->projectChat($project));

        $this->artisan('projects:backfill-chats')->assertSuccessful();

        $chat = $this->projectChat($project);
        $this->assertNotNull($chat);
        $this->assertTrue($chat->hasParticipant($creator));

        // Idempotent: a second run creates nothing new.
        $this->artisan('projects:backfill-chats')->assertSuccessful();
        $this->assertSame(1, Conversation::query()->where('type', 'project')->count());
    }
}
