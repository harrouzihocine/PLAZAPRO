<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RbacTest extends TestCase
{
    use RefreshDatabase;

    private function userWithPermissions(array $slugs, bool $isAgent = false): User
    {
        $role = Role::factory()->create(['is_agent' => $isAgent]);
        $permissionIds = collect($slugs)->map(
            fn (string $slug) => Permission::firstOrCreate(['slug' => $slug], ['name' => $slug])->id
        );
        $role->permissions()->sync($permissionIds);

        return User::factory()->create(['role_id' => $role->id]);
    }

    public function test_route_is_forbidden_without_the_permission(): void
    {
        $user = $this->userWithPermissions([]); // no permissions
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/audit')->assertForbidden(); // 403
    }

    public function test_route_is_allowed_with_the_permission(): void
    {
        $user = $this->userWithPermissions(['audit.view']);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/audit')->assertOk();
    }

    public function test_super_admin_bypasses_permission_checks(): void
    {
        $role = Role::factory()->create(['slug' => 'super-admin']);
        $admin = User::factory()->create(['role_id' => $role->id]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/audit')->assertOk(); // allowed despite no explicit audit.view
    }

    public function test_only_agent_flagged_users_are_visit_assignment_candidates(): void
    {
        $this->userWithPermissions([], isAgent: true);   // one agent
        $this->userWithPermissions([], isAgent: false);  // one non-agent

        $agents = User::query()
            ->whereHas('role', fn ($q) => $q->where('is_agent', true))
            ->get();

        $this->assertCount(1, $agents);
        $this->assertTrue($agents->first()->isAgent());
    }
}
