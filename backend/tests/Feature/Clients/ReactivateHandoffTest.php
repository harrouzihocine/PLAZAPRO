<?php

declare(strict_types=1);

namespace Tests\Feature\Clients;

use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Collaboration\Notifications\DomainNotification;
use App\Modules\Pipeline\Models\Visit;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReactivateHandoffTest extends TestCase
{
    use RefreshDatabase;

    /** @param  list<string>  $slugs */
    private function userWith(array $slugs): User
    {
        $role = Role::factory()->create();
        $role->permissions()->sync(collect($slugs)->map(
            fn (string $s) => Permission::firstOrCreate(['slug' => $s], ['name' => $s])->id
        ));

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function archivedProject(User $creator, array $viewerIds = []): ClientProject
    {
        $project = ClientProject::factory()->create([
            'client_id' => Client::factory()->create()->id,
            'created_by' => $creator->id,
        ]);
        foreach ($viewerIds as $id) {
            $project->viewers()->attach($id, ['added_by' => $creator->id]);
        }
        $project->archive('Lost');

        return $project->fresh();
    }

    public function test_plain_reactivate_when_no_team_is_chosen(): void
    {
        Notification::fake();
        $creator = $this->userWith(['projects.create']);
        $project = $this->archivedProject($creator);

        Sanctum::actingAs($this->userWith(['projects.manage']));

        $this->postJson("/api/v1/projects/{$project->id}/reactivate")->assertOk();

        $this->assertTrue($project->fresh()->isActive());
        Notification::assertNothingSent();
    }

    public function test_unchanged_team_is_a_plain_reactivate(): void
    {
        Notification::fake();
        $creator = $this->userWith(['projects.create']);
        $viewer = $this->userWith(['projects.create']);
        $project = $this->archivedProject($creator, [$viewer->id]);

        Sanctum::actingAs($this->userWith(['projects.manage']));

        // Same set as today's contributors → plain reactivate, no hand-off.
        $this->postJson("/api/v1/projects/{$project->id}/reactivate", [
            'handler_ids' => [$creator->id, $viewer->id],
            'mode' => 'in_place',
        ])->assertOk();

        $this->assertTrue($project->fresh()->isActive());
        Notification::assertNothingSent();
    }

    public function test_in_place_replaces_contributors_grants_details_and_notifies(): void
    {
        Notification::fake();
        $creator = $this->userWith(['projects.create']);
        $oldViewer = $this->userWith(['projects.create']);
        $newHandler = $this->userWith(['projects.create']);
        $project = $this->archivedProject($creator, [$oldViewer->id]);

        Sanctum::actingAs($this->userWith(['projects.manage']));

        $this->postJson("/api/v1/projects/{$project->id}/reactivate", [
            'handler_ids' => [$creator->id, $newHandler->id],
            'mode' => 'in_place',
        ])->assertOk()->assertJsonPath('data.id', $project->id);

        $fresh = $project->fresh();
        $this->assertTrue($fresh->isActive());
        // New handler is a live contributor; the old viewer is hidden; creator kept.
        $this->assertTrue($fresh->viewers()->wherePivotNull('hidden_at')->whereKey($newHandler->id)->exists());
        $this->assertTrue($fresh->viewers()->wherePivotNotNull('hidden_at')->whereKey($oldViewer->id)->exists());
        $this->assertDatabaseHas('client_detail_grants', ['client_id' => $fresh->client_id, 'user_id' => $newHandler->id]);
        Notification::assertSentTo($newHandler, DomainNotification::class);
        Notification::assertNothingSentTo($oldViewer);
    }

    public function test_separate_forks_a_new_siloed_project_and_leaves_the_original_archived(): void
    {
        Notification::fake();
        $creator = $this->userWith(['projects.create']);
        $primary = $this->userWith(['projects.create']);
        $mate = $this->userWith(['projects.create']);
        $project = $this->archivedProject($creator);

        Sanctum::actingAs($this->userWith(['projects.manage']));

        $res = $this->postJson("/api/v1/projects/{$project->id}/reactivate", [
            'handler_ids' => [$creator->id, $primary->id, $mate->id],
            'mode' => 'separate',
            'primary_id' => $primary->id,
        ])->assertOk();

        // Original stays archived.
        $this->assertTrue($project->fresh()->isArchived());

        // A new siloed project, owned by the primary, continuing the original.
        $newId = $res->json('data.id');
        $this->assertNotSame($project->id, $newId);
        $new = ClientProject::query()->findOrFail($newId);
        $this->assertTrue($new->isActive());
        $this->assertSame($project->id, $new->continued_from_project_id);
        $this->assertTrue((bool) $new->hidden_from_owner);
        $this->assertSame($primary->id, $new->created_by);
        $this->assertTrue($new->viewers()->wherePivotNull('hidden_at')->whereKey($mate->id)->exists());
        $this->assertDatabaseHas('client_detail_grants', ['client_id' => $new->client_id, 'user_id' => $mate->id]);
        Notification::assertSentTo($primary, DomainNotification::class);
        Notification::assertSentTo($mate, DomainNotification::class);
    }

    public function test_separate_requires_primary_to_be_one_of_the_handlers(): void
    {
        $creator = $this->userWith(['projects.create']);
        $primary = $this->userWith(['projects.create']);
        $outsider = $this->userWith(['projects.create']);
        $project = $this->archivedProject($creator);

        Sanctum::actingAs($this->userWith(['projects.manage']));

        $this->postJson("/api/v1/projects/{$project->id}/reactivate", [
            'handler_ids' => [$creator->id, $primary->id],
            'mode' => 'separate',
            'primary_id' => $outsider->id,
        ])->assertStatus(422);
    }

    public function test_a_handler_without_projects_create_is_rejected(): void
    {
        $creator = $this->userWith(['projects.create']);
        $weak = $this->userWith(['clients.view']); // cannot open a project
        $project = $this->archivedProject($creator);

        Sanctum::actingAs($this->userWith(['projects.manage']));

        $this->postJson("/api/v1/projects/{$project->id}/reactivate", [
            'handler_ids' => [$creator->id, $weak->id],
            'mode' => 'in_place',
        ])->assertStatus(422);
    }

    public function test_reactivate_requires_projects_manage(): void
    {
        $project = $this->archivedProject($this->userWith(['projects.create']));
        Sanctum::actingAs($this->userWith(['clients.view']));

        $this->postJson("/api/v1/projects/{$project->id}/reactivate")->assertForbidden();
    }

    public function test_handoff_preview_lists_opener_contributors_and_in_site_agents(): void
    {
        $creator = $this->userWith(['projects.create']);
        $viewer = $this->userWith(['projects.create']);
        $fieldAgent = User::factory()->agent()->create();
        $project = $this->archivedProject($creator, [$viewer->id]);
        Visit::factory()->inSite()->create([
            'client_id' => $project->client_id,
            'client_project_id' => $project->id,
            'agent_id' => $fieldAgent->id,
        ]);

        Sanctum::actingAs($this->userWith(['projects.manage']));

        $this->getJson("/api/v1/projects/{$project->id}/handoff-preview")
            ->assertOk()
            ->assertJsonPath('data.opened_by.id', $creator->id)
            ->assertJsonPath('data.in_site_agents.0.id', $fieldAgent->id)
            ->assertJsonPath('data.contributors.0.is_creator', true);
    }

    public function test_project_handlers_picker_lists_only_projects_create_holders(): void
    {
        $handler = $this->userWith(['projects.create']);
        $this->userWith(['clients.view']); // excluded

        Sanctum::actingAs($this->userWith(['clients.view']));

        $ids = collect($this->getJson('/api/v1/project-handlers')->assertOk()->json('data'))->pluck('id');
        $this->assertContains($handler->id, $ids);
        $this->assertCount(1, $ids);
    }
}
