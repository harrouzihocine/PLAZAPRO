<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RoleTest extends TestCase
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
        return $this->userWithPermissions(['roles.manage']);
    }

    private function permission(string $slug): Permission
    {
        return Permission::firstOrCreate(['slug' => $slug], ['name' => $slug, 'group' => 'Test']);
    }

    public function test_admin_can_create_a_role_with_permissions(): void
    {
        $p1 = $this->permission('units.view');
        $p2 = $this->permission('clients.view');
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/v1/roles', [
            'name' => 'Sales Agent',
            'is_agent' => true,
            'permissions' => [$p1->id, $p2->id],
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Sales Agent')
            ->assertJsonPath('data.is_agent', true)
            ->assertJsonPath('data.slug', 'sales-agent');

        $role = Role::where('slug', 'sales-agent')->firstOrFail();
        $this->assertEqualsCanonicalizing([$p1->id, $p2->id], $role->permissions->pluck('id')->all());
    }

    public function test_admin_can_toggle_is_agent_and_sync_permissions(): void
    {
        $role = Role::factory()->create(['is_agent' => false]);
        $p = $this->permission('tasks.manage');
        Sanctum::actingAs($this->admin());

        $this->putJson("/api/v1/roles/{$role->id}", ['is_agent' => true, 'permissions' => [$p->id]])
            ->assertOk()
            ->assertJsonPath('data.is_agent', true);

        $this->assertTrue($role->fresh()->is_agent);
        $this->assertEqualsCanonicalizing([$p->id], $role->fresh()->permissions->pluck('id')->all());
    }

    public function test_cancelling_a_role_keeps_the_row(): void
    {
        $role = Role::factory()->create();
        Sanctum::actingAs($this->admin());

        $this->deleteJson("/api/v1/roles/{$role->id}")->assertOk();
        $this->assertDatabaseHas('roles', ['id' => $role->id, 'status' => 'cancelled']);
    }

    public function test_super_admin_role_cannot_be_cancelled(): void
    {
        $superAdmin = Role::factory()->create(['slug' => 'super-admin']);
        Sanctum::actingAs($this->admin());

        $this->deleteJson("/api/v1/roles/{$superAdmin->id}")->assertStatus(422);
        $this->assertDatabaseHas('roles', ['id' => $superAdmin->id, 'status' => 'active']);
    }

    public function test_cannot_cancel_a_role_that_still_has_active_users(): void
    {
        $role = Role::factory()->create();
        User::factory()->create(['role_id' => $role->id]);
        Sanctum::actingAs($this->admin());

        $this->deleteJson("/api/v1/roles/{$role->id}")->assertStatus(422);
    }

    public function test_role_writes_and_permission_catalogue_require_roles_manage(): void
    {
        Sanctum::actingAs($this->userWithPermissions([])); // authed, but no roles.manage

        $this->postJson('/api/v1/roles', ['name' => 'X'])->assertForbidden();
        $this->getJson('/api/v1/permissions')->assertForbidden();
    }

    public function test_roles_index_is_readable_by_any_authenticated_user(): void
    {
        Role::factory()->create(['name' => 'Viewer']);
        Sanctum::actingAs($this->userWithPermissions([]));

        $this->getJson('/api/v1/roles')->assertOk()->assertJsonFragment(['name' => 'Viewer']);
    }
}
