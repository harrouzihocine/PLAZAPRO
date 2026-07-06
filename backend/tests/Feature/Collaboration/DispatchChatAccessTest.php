<?php

declare(strict_types=1);

namespace Tests\Feature\Collaboration;

use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Clients\Models\ShortlistItem;
use App\Modules\Collaboration\Actions\EnsureProjectConversation;
use App\Modules\Collaboration\Models\Conversation;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Pipeline\Models\NextAction;
use App\Modules\Pipeline\Models\Visit;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Dispatch-driven project-chat access: assigning a field agent to a project's
 * in-site visit lets him read AND write that project's chat (a narrow
 * ROLE_FIELD_AGENT grant, NOT contributor membership); un-assigning him from
 * the dispatch board downgrades him to read-only, keeping his history.
 */
class DispatchChatAccessTest extends TestCase
{
    use RefreshDatabase;

    /** @param  list<string>  $slugs */
    private function userWith(array $slugs, bool $isAgent = false): User
    {
        $role = Role::factory()->create(['is_agent' => $isAgent]);
        $ids = collect($slugs)->map(
            fn (string $slug) => Permission::firstOrCreate(['slug' => $slug], ['name' => $slug])->id
        );
        $role->permissions()->sync($ids);

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function projectWithShortlist(?int $createdBy = null): ClientProject
    {
        $project = ClientProject::factory()->create([
            'client_id' => Client::factory()->create()->id,
            'created_by' => $createdBy,
        ]);
        ShortlistItem::factory()->create([
            'client_project_id' => $project->id,
            'shortlistable_type' => 'unit',
            'shortlistable_id' => Unit::factory()->create()->id,
            'state' => 'shortlisted',
        ]);

        return $project;
    }

    private function pendingInSitePlan(ClientProject $project, ?int $agentId = null): NextAction
    {
        return NextAction::factory()->create([
            'subject_type' => 'client_project', 'subject_id' => $project->id,
            'type' => 'in_site_visit', 'state' => 'pending',
            'assigned_to' => $agentId, 'due_at' => now()->addDay(),
        ]);
    }

    private function projectChat(ClientProject $project): ?Conversation
    {
        return Conversation::query()
            ->where('type', 'project')
            ->where('subject_type', 'client_project')
            ->where('subject_id', $project->id)
            ->first();
    }

    private function role(Conversation $chat, User $user): ?string
    {
        return $chat->participants()->where('users.id', $user->id)->value('conversation_user.role');
    }

    public function test_dispatching_an_in_site_visit_grants_the_agent_read_write_chat_access(): void
    {
        Notification::fake();

        $dispatcher = $this->userWith(['visits.dispatch']);
        $creator = $this->userWith(['chat.use']);
        $agent = $this->userWith(['chat.use'], isAgent: true);
        $project = $this->projectWithShortlist($creator->id);
        $action = $this->pendingInSitePlan($project);

        Sanctum::actingAs($dispatcher);
        $this->postJson('/api/v1/dispatch/assign', ['changes' => [
            ['kind' => 'action', 'id' => $action->id, 'agent_id' => $agent->id, 'due_date' => now()->addDay()->toDateString()],
        ]])->assertOk();

        $chat = $this->projectChat($project);
        $this->assertSame(Conversation::ROLE_FIELD_AGENT, $this->role($chat, $agent));

        // He reads the thread, sees can_post = true, and may write.
        Sanctum::actingAs($agent);
        $this->getJson("/api/v1/conversations/{$chat->id}")
            ->assertOk()
            ->assertJsonPath('data.can_post', true);
        $this->postJson("/api/v1/conversations/{$chat->id}/messages", ['body' => 'on my way'])
            ->assertCreated();
    }

    public function test_returning_the_visit_to_the_pool_downgrades_him_to_read_only(): void
    {
        Notification::fake();

        $dispatcher = $this->userWith(['visits.dispatch']);
        $creator = $this->userWith(['chat.use']);
        $agent = $this->userWith(['chat.use'], isAgent: true);
        $project = $this->projectWithShortlist($creator->id);

        $action = $this->pendingInSitePlan($project, $agent->id);
        $visit = Visit::factory()->inSite()->create([
            'client_id' => $project->client_id, 'client_project_id' => $project->id,
            'agent_id' => $agent->id, 'next_action_id' => $action->id,
            'scheduled_at' => now()->addDay(), 'completed_at' => null,
        ]);

        // Materialize the grant (the assign path fires VisitAssigned).
        $chat = app(EnsureProjectConversation::class)->handle($project);
        $chat->participants()->attach($agent->id, ['role' => Conversation::ROLE_FIELD_AGENT, 'joined_at' => now()]);

        Sanctum::actingAs($dispatcher);
        $this->postJson('/api/v1/dispatch/assign', ['changes' => [
            ['kind' => 'visit', 'id' => $visit->id, 'agent_id' => null],
        ]])->assertOk();

        $this->assertSame(Conversation::ROLE_FIELD_AGENT_OBSERVER, $this->role($chat->fresh(), $agent));

        // Still a participant (history stays readable) but the composer locks.
        Sanctum::actingAs($agent);
        $this->getJson("/api/v1/conversations/{$chat->id}")
            ->assertOk()
            ->assertJsonPath('data.can_post', false);
        $this->getJson("/api/v1/conversations/{$chat->id}/messages")->assertOk();
        $this->postJson("/api/v1/conversations/{$chat->id}/messages", ['body' => 'still here?'])
            ->assertForbidden();
    }

    public function test_reassigning_to_another_agent_downgrades_the_previous_one(): void
    {
        Notification::fake();

        $dispatcher = $this->userWith(['visits.dispatch']);
        $creator = $this->userWith(['chat.use']);
        $first = $this->userWith(['chat.use'], isAgent: true);
        $second = $this->userWith(['chat.use'], isAgent: true);
        $project = $this->projectWithShortlist($creator->id);

        $action = $this->pendingInSitePlan($project, $first->id);
        $visit = Visit::factory()->inSite()->create([
            'client_id' => $project->client_id, 'client_project_id' => $project->id,
            'agent_id' => $first->id, 'next_action_id' => $action->id,
            'scheduled_at' => now()->addDay(), 'completed_at' => null,
        ]);

        $chat = app(EnsureProjectConversation::class)->handle($project);
        $chat->participants()->attach($first->id, ['role' => Conversation::ROLE_FIELD_AGENT, 'joined_at' => now()]);

        Sanctum::actingAs($dispatcher);
        $this->postJson('/api/v1/dispatch/assign', ['changes' => [
            ['kind' => 'visit', 'id' => $visit->id, 'agent_id' => $second->id],
        ]])->assertOk();

        $chat->refresh();
        $this->assertSame(Conversation::ROLE_FIELD_AGENT_OBSERVER, $this->role($chat, $first));
        $this->assertSame(Conversation::ROLE_FIELD_AGENT, $this->role($chat, $second));
    }

    public function test_a_real_contributor_dispatched_as_agent_is_never_downgraded(): void
    {
        Notification::fake();

        $dispatcher = $this->userWith(['visits.dispatch']);
        // The project creator is themselves the dispatched field agent.
        $agent = $this->userWith(['chat.use'], isAgent: true);
        $project = $this->projectWithShortlist($agent->id);

        $action = $this->pendingInSitePlan($project, $agent->id);
        $visit = Visit::factory()->inSite()->create([
            'client_id' => $project->client_id, 'client_project_id' => $project->id,
            'agent_id' => $agent->id, 'next_action_id' => $action->id,
            'scheduled_at' => now()->addDay(), 'completed_at' => null,
        ]);

        $chat = app(EnsureProjectConversation::class)->handle($project);
        $this->assertSame('admin', $this->role($chat, $agent));

        // Return to the pool — his contributor (admin) role is untouched.
        Sanctum::actingAs($dispatcher);
        $this->postJson('/api/v1/dispatch/assign', ['changes' => [
            ['kind' => 'visit', 'id' => $visit->id, 'agent_id' => null],
        ]])->assertOk();

        $this->assertSame('admin', $this->role($chat->fresh(), $agent));
    }

    public function test_contributor_sync_preserves_and_then_promotes_a_field_agent(): void
    {
        $creator = $this->userWith(['clients.view', 'clients.view_all', 'clients.create', 'projects.contributors', 'chat.use']);
        $agent = $this->userWith(['chat.use'], isAgent: true);
        $project = $this->projectWithShortlist($creator->id);

        $ensure = app(EnsureProjectConversation::class);
        $chat = $ensure->handle($project);
        $chat->participants()->attach($agent->id, ['role' => Conversation::ROLE_FIELD_AGENT, 'joined_at' => now()]);

        // A later contributor change re-syncs participants — the field-agent
        // grant must survive (it is not contributor-driven).
        $otherViewer = $this->userWith(['chat.use']);
        Sanctum::actingAs($creator);
        $this->postJson("/api/v1/projects/{$project->id}/viewers", ['user_id' => $otherViewer->id])->assertOk();
        $this->assertSame(Conversation::ROLE_FIELD_AGENT, $this->role($chat->fresh(), $agent));

        // Adding the field agent himself as a real viewer PROMOTES his row.
        $this->postJson("/api/v1/projects/{$project->id}/viewers", ['user_id' => $agent->id])->assertOk();
        $this->assertSame('member', $this->role($chat->fresh(), $agent));
    }
}
