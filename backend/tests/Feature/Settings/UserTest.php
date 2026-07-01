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

class UserTest extends TestCase
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
        return $this->userWithPermissions(['users.manage']);
    }

    public function test_admin_can_create_a_user_with_a_single_role(): void
    {
        $role = Role::factory()->create();
        $dept = Department::factory()->create();
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/v1/users', [
            'name' => 'Sam Seller',
            'email' => 'sam@plaza.local',
            'password' => 'Str0ng-Passw0rd!',
            'role_id' => $role->id,
            'department_id' => $dept->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.email', 'sam@plaza.local')
            ->assertJsonPath('data.role.id', $role->id)
            ->assertJsonPath('data.department.id', $dept->id);

        $this->assertDatabaseHas('users', [
            'email' => 'sam@plaza.local',
            'role_id' => $role->id,
            'is_active' => true,
        ]);
    }

    public function test_creating_a_user_requires_exactly_one_role(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/v1/users', [
            'name' => 'No Role',
            'email' => 'norole@plaza.local',
            'password' => 'Str0ng-Passw0rd!',
        ])->assertStatus(422)->assertJsonValidationErrors('role_id');
    }

    public function test_password_must_satisfy_default_strength_rules(): void
    {
        $role = Role::factory()->create();
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/v1/users', [
            'name' => 'Weak',
            'email' => 'weak@plaza.local',
            'password' => '123',
            'role_id' => $role->id,
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_update_leaves_password_untouched_when_omitted(): void
    {
        $user = User::factory()->create();
        $original = $user->password;
        Sanctum::actingAs($this->admin());

        $this->putJson("/api/v1/users/{$user->id}", ['name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed');

        $this->assertSame($original, $user->fresh()->password);
    }

    public function test_deactivating_a_user_keeps_the_row_and_blocks_nothing_destructive(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        Sanctum::actingAs($this->admin());

        $this->putJson("/api/v1/users/{$user->id}/active", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'is_active' => false, 'status' => 'active']);
    }

    public function test_cancelling_a_user_keeps_the_row(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($this->admin());

        $this->deleteJson("/api/v1/users/{$user->id}")->assertOk();
        $this->assertDatabaseHas('users', ['id' => $user->id, 'status' => 'cancelled']);
    }

    public function test_admin_cannot_deactivate_their_own_account(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $this->putJson("/api/v1/users/{$admin->id}/active", ['is_active' => false])
            ->assertStatus(422);
        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_admin_cannot_cancel_their_own_account(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $this->deleteJson("/api/v1/users/{$admin->id}")->assertStatus(422);
        $this->assertDatabaseHas('users', ['id' => $admin->id, 'status' => 'active']);
    }

    public function test_index_can_filter_by_role_and_active_state(): void
    {
        $roleA = Role::factory()->create();
        $roleB = Role::factory()->create();
        User::factory()->create(['name' => 'Alice', 'role_id' => $roleA->id, 'is_active' => true]);
        User::factory()->create(['name' => 'Bob', 'role_id' => $roleB->id, 'is_active' => false]);
        Sanctum::actingAs($this->admin());

        $this->getJson("/api/v1/users?role_id={$roleA->id}")
            ->assertOk()
            ->assertJsonFragment(['name' => 'Alice'])
            ->assertJsonMissing(['name' => 'Bob']);

        $this->getJson('/api/v1/users?is_active=0')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Bob'])
            ->assertJsonMissing(['name' => 'Alice']);
    }

    public function test_user_endpoints_require_users_manage(): void
    {
        Sanctum::actingAs($this->userWithPermissions([])); // authed, no users.manage

        $this->getJson('/api/v1/users')->assertForbidden();
        $this->postJson('/api/v1/users', [])->assertForbidden();
    }
}
