<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Modules\Settings\Models\Department;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DepartmentTest extends TestCase
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

    private function admin(): User
    {
        return $this->userWithPermissions(['settings.manage']);
    }

    public function test_admin_can_create_a_department_with_an_auto_generated_slug(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/v1/departments', ['name' => 'Sales & Marketing'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Sales & Marketing')
            ->assertJsonPath('data.slug', 'sales-marketing');

        $this->assertDatabaseHas('departments', [
            'name' => 'Sales & Marketing', 'slug' => 'sales-marketing', 'status' => 'active',
        ]);
    }

    public function test_admin_can_rename_a_department(): void
    {
        $dept = Department::factory()->create(['name' => 'Ops']);
        Sanctum::actingAs($this->admin());

        $this->putJson("/api/v1/departments/{$dept->id}", ['name' => 'Operations'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Operations');
    }

    public function test_index_is_readable_by_any_authenticated_user(): void
    {
        Department::factory()->create(['name' => 'Finance']);
        Sanctum::actingAs($this->userWithPermissions([])); // no settings.manage

        $this->getJson('/api/v1/departments')->assertOk()->assertJsonFragment(['name' => 'Finance']);
    }

    public function test_writes_require_settings_manage_permission(): void
    {
        Sanctum::actingAs($this->userWithPermissions([]));

        $this->postJson('/api/v1/departments', ['name' => 'Ops'])->assertForbidden();
    }

    public function test_cancelling_a_department_keeps_the_row(): void
    {
        $dept = Department::factory()->create();
        Sanctum::actingAs($this->admin());

        $this->deleteJson("/api/v1/departments/{$dept->id}")->assertOk();

        // No hard delete — the row survives with a cancelled status.
        $this->assertDatabaseHas('departments', ['id' => $dept->id, 'status' => 'cancelled']);
    }

    public function test_cannot_cancel_a_department_that_still_has_active_users(): void
    {
        $dept = Department::factory()->create();
        User::factory()->create(['department_id' => $dept->id]);
        Sanctum::actingAs($this->admin());

        $this->deleteJson("/api/v1/departments/{$dept->id}")->assertStatus(422);
        $this->assertDatabaseHas('departments', ['id' => $dept->id, 'status' => 'active']);
    }

    public function test_slug_must_be_unique(): void
    {
        Department::factory()->create(['slug' => 'sales']);
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/v1/departments', ['name' => 'Sales', 'slug' => 'sales'])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('slug');
    }
}
