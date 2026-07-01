<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Modules\Clients\Models\ClientProject;
use App\Modules\Payments\Models\Versement;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Archive-only-without-payments (a guide test rule): a deal that has active
 * versements cannot be archived; with none it succeeds.
 */
class ArchiveProjectTest extends TestCase
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

    private function manager(): User
    {
        return $this->userWithPermissions(['clients.view', 'clients.manage']);
    }

    public function test_a_deal_with_active_versements_cannot_be_archived(): void
    {
        $project = ClientProject::factory()->create(['total_price' => '1000.00']);
        Versement::factory()->create(['client_project_id' => $project->id, 'amount' => '500.00']);
        Sanctum::actingAs($this->manager());

        $this->deleteJson("/api/v1/projects/{$project->id}", ['reason' => 'Closing out'])
            ->assertStatus(422);

        $this->assertDatabaseHas('client_projects', ['id' => $project->id, 'status' => 'active']);
    }

    public function test_a_deal_with_no_active_versements_can_be_archived(): void
    {
        $project = ClientProject::factory()->create(['total_price' => '1000.00']);
        Sanctum::actingAs($this->manager());

        $this->deleteJson("/api/v1/projects/{$project->id}", ['reason' => 'Closing out'])
            ->assertOk();

        $this->assertDatabaseHas('client_projects', ['id' => $project->id, 'status' => 'cancelled']);
    }

    public function test_a_deal_whose_only_versement_was_cancelled_can_be_archived(): void
    {
        $project = ClientProject::factory()->create(['total_price' => '1000.00']);
        Versement::factory()->create([
            'client_project_id' => $project->id, 'amount' => '500.00', 'status' => 'cancelled',
        ]);
        Sanctum::actingAs($this->manager());

        $this->deleteJson("/api/v1/projects/{$project->id}", ['reason' => 'Closing out'])
            ->assertOk();

        $this->assertDatabaseHas('client_projects', ['id' => $project->id, 'status' => 'cancelled']);
    }
}
