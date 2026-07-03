<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Modules\Clients\Models\ClientProject;
use App\Modules\Payments\Models\PaymentSchedule;
use App\Modules\Payments\Models\Versement;
use App\Modules\Settings\Models\DynamicList;
use App\Modules\Settings\Models\DynamicListItem;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The deal lifecycle beyond active:
 *   - Archive (POST /archive)   → reversible; hidden until reactivated. Requires an
 *     archive reason and is BLOCKED once any payment has been recorded.
 *   - Remove  (DELETE)          → terminal, and allowed only while the project is
 *     still empty (no calls/visits/shortlist/deals/payments in any state) — a
 *     filled project is part of the client's story and must be archived instead.
 * Neither hard-deletes.
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
        return $this->userWithPermissions(['clients.view', 'clients.manage', 'projects.view_all']);
    }

    /** An archive_reasons item id — required to archive a deal. */
    private function archiveReason(): int
    {
        $list = DynamicList::firstOrCreate(['key' => 'archive_reasons'], ['name' => 'Archive Reasons', 'is_system' => false]);

        return DynamicListItem::firstOrCreate(
            ['dynamic_list_id' => $list->id, 'value' => 'changed_mind'],
            ['label' => 'Changed mind', 'is_active' => true],
        )->id;
    }

    // --- Remove (DELETE): allowed only while the project is still empty ---

    public function test_removing_an_empty_deal_cancels_it(): void
    {
        $project = ClientProject::factory()->create();
        Sanctum::actingAs($this->manager());

        $this->deleteJson("/api/v1/projects/{$project->id}", ['reason' => 'Opened by mistake'])
            ->assertOk();

        $this->assertDatabaseHas('client_projects', ['id' => $project->id, 'status' => 'cancelled']);
    }

    public function test_removing_a_filled_deal_is_blocked(): void
    {
        $project = ClientProject::factory()->create(['total_price' => '1000.00']);
        $schedule = PaymentSchedule::factory()->create(['client_project_id' => $project->id]);
        $versement = Versement::factory()->create(['client_project_id' => $project->id, 'amount' => '500.00']);
        Sanctum::actingAs($this->manager());

        $this->deleteJson("/api/v1/projects/{$project->id}", ['reason' => 'Closing out'])
            ->assertStatus(422);

        // Nothing was touched — a filled project must be archived, not removed.
        $this->assertDatabaseHas('client_projects', ['id' => $project->id, 'status' => 'active']);
        $this->assertDatabaseHas('payment_schedules', ['id' => $schedule->id, 'status' => 'active']);
        $this->assertDatabaseHas('versements', ['id' => $versement->id, 'status' => 'active']);
    }

    public function test_cancelled_history_still_blocks_removal(): void
    {
        // Even a child that was itself cancelled is history worth keeping.
        $project = ClientProject::factory()->create(['total_price' => '1000.00']);
        Versement::factory()->create([
            'client_project_id' => $project->id,
            'amount' => '500.00',
            'status' => 'cancelled',
            'cancellation_reason' => 'Duplicate entry',
        ]);
        Sanctum::actingAs($this->manager());

        $this->deleteJson("/api/v1/projects/{$project->id}", ['reason' => 'Closing out'])
            ->assertStatus(422);

        $this->assertDatabaseHas('client_projects', ['id' => $project->id, 'status' => 'active']);
    }

    // --- Archive (POST /archive): reversible, hidden, requires a reason ---

    public function test_archiving_a_deal_requires_a_reason(): void
    {
        $project = ClientProject::factory()->create();
        Sanctum::actingAs($this->manager());

        $this->postJson("/api/v1/projects/{$project->id}/archive", [])
            ->assertStatus(422)->assertJsonValidationErrorFor('archive_reason_id');
    }

    public function test_archiving_a_deal_hides_it_and_its_plan_and_stores_the_reason(): void
    {
        $project = ClientProject::factory()->create(['total_price' => '1000.00']);
        $schedule = PaymentSchedule::factory()->create(['client_project_id' => $project->id]);
        Sanctum::actingAs($this->manager());

        $this->postJson("/api/v1/projects/{$project->id}/archive", ['archive_reason_id' => $this->archiveReason(), 'note' => 'Bought elsewhere'])
            ->assertOk();

        $this->assertDatabaseHas('client_projects', [
            'id' => $project->id, 'status' => 'archived', 'cancellation_reason' => 'Changed mind — Bought elsewhere',
        ]);
        $this->assertDatabaseHas('payment_schedules', ['id' => $schedule->id, 'status' => 'archived']);

        // Gone from the default list, present under ?status=archived.
        $this->getJson("/api/v1/clients/{$project->client_id}/projects")
            ->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/clients/{$project->client_id}/projects?status=archived")
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_archiving_is_blocked_once_a_payment_is_recorded(): void
    {
        $project = ClientProject::factory()->create(['total_price' => '1000.00']);
        Versement::factory()->create(['client_project_id' => $project->id, 'amount' => '500.00']);
        Sanctum::actingAs($this->manager());

        $this->postJson("/api/v1/projects/{$project->id}/archive", ['archive_reason_id' => $this->archiveReason()])
            ->assertStatus(422);

        $this->assertDatabaseHas('client_projects', ['id' => $project->id, 'status' => 'active']);
    }

    public function test_reactivating_an_archived_deal_restores_it_and_its_plan(): void
    {
        $project = ClientProject::factory()->create(['total_price' => '1000.00']);
        $schedule = PaymentSchedule::factory()->create(['client_project_id' => $project->id]);
        Sanctum::actingAs($this->manager());

        $this->postJson("/api/v1/projects/{$project->id}/archive", ['archive_reason_id' => $this->archiveReason()])->assertOk();
        $this->postJson("/api/v1/projects/{$project->id}/reactivate")->assertOk();

        // Reactivating clears the archive reason and restores the plan.
        $this->assertDatabaseHas('client_projects', ['id' => $project->id, 'status' => 'active', 'cancellation_reason' => null]);
        $this->assertDatabaseHas('payment_schedules', ['id' => $schedule->id, 'status' => 'active']);
    }

    public function test_reactivate_does_not_revive_children_cancelled_before_the_archive(): void
    {
        $project = ClientProject::factory()->create(['total_price' => '1000.00']);
        $cancelled = PaymentSchedule::factory()->create([
            'client_project_id' => $project->id, 'status' => 'cancelled', 'cancellation_reason' => 'Correction',
        ]);
        $live = PaymentSchedule::factory()->create(['client_project_id' => $project->id]);
        Sanctum::actingAs($this->manager());

        $this->postJson("/api/v1/projects/{$project->id}/archive", ['archive_reason_id' => $this->archiveReason()])->assertOk();
        $this->postJson("/api/v1/projects/{$project->id}/reactivate")->assertOk();

        // The live one round-trips back to active; the pre-cancelled one stays cancelled.
        $this->assertDatabaseHas('payment_schedules', ['id' => $live->id, 'status' => 'active']);
        $this->assertDatabaseHas('payment_schedules', ['id' => $cancelled->id, 'status' => 'cancelled']);
    }

    // --- Guards ---

    public function test_a_removed_deal_cannot_be_archived(): void
    {
        $project = ClientProject::factory()->create();
        Sanctum::actingAs($this->manager());

        $this->deleteJson("/api/v1/projects/{$project->id}", ['reason' => 'Gone'])->assertOk();

        $this->postJson("/api/v1/projects/{$project->id}/archive", ['archive_reason_id' => $this->archiveReason()])
            ->assertStatus(422);
    }

    public function test_a_deal_that_is_not_archived_cannot_be_reactivated(): void
    {
        $project = ClientProject::factory()->create();
        Sanctum::actingAs($this->manager());

        $this->postJson("/api/v1/projects/{$project->id}/reactivate")->assertStatus(422);
    }

    public function test_archive_and_reactivate_require_the_manage_permission(): void
    {
        $project = ClientProject::factory()->create();
        Sanctum::actingAs($this->userWithPermissions(['clients.view']));

        $this->postJson("/api/v1/projects/{$project->id}/archive", ['archive_reason_id' => 1])->assertForbidden();
        $this->postJson("/api/v1/projects/{$project->id}/reactivate")->assertForbidden();
    }
}
