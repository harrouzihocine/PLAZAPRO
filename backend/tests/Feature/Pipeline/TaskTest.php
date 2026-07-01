<?php

declare(strict_types=1);

namespace Tests\Feature\Pipeline;

use App\Modules\Pipeline\Models\Task;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TaskTest extends TestCase
{
    use RefreshDatabase;

    /** @param  list<string>  $slugs */
    private function userWithPermissions(array $slugs): User
    {
        $role = Role::factory()->create();
        $ids = collect($slugs)->map(
            fn (string $slug) => Permission::firstOrCreate(['slug' => $slug], ['name' => $slug])->id
        );
        $role->permissions()->sync($ids);

        return User::factory()->create(['role_id' => $role->id]);
    }

    public function test_a_task_can_be_created_and_defaults_to_the_actor(): void
    {
        $user = $this->userWithPermissions(['tasks.manage']);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/tasks', ['title' => 'Call the notary'])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Call the notary')
            ->assertJsonPath('data.state', 'open')
            ->assertJsonPath('data.assigned_to.id', $user->id);
    }

    public function test_a_task_can_be_completed(): void
    {
        $task = Task::factory()->create();
        Sanctum::actingAs($this->userWithPermissions(['tasks.manage']));

        $this->postJson("/api/v1/tasks/{$task->id}/complete")
            ->assertOk()
            ->assertJsonPath('data.state', 'done');
    }

    public function test_cancelling_a_task_keeps_the_record(): void
    {
        $task = Task::factory()->create();
        Sanctum::actingAs($this->userWithPermissions(['tasks.manage']));

        $this->deleteJson("/api/v1/tasks/{$task->id}", ['reason' => 'No longer needed'])
            ->assertOk();

        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'state' => 'cancelled', 'status' => 'cancelled']);
    }

    public function test_tasks_require_tasks_manage(): void
    {
        Sanctum::actingAs($this->userWithPermissions(['clients.view']));

        $this->postJson('/api/v1/tasks', ['title' => 'X'])->assertForbidden();
    }
}
