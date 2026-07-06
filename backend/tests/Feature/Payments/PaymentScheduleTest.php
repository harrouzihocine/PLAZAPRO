<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Modules\Clients\Models\ClientProject;
use App\Modules\Payments\Actions\MarkSchedulesOverdue;
use App\Modules\Payments\Enums\ScheduleState;
use App\Modules\Payments\Models\PaymentSchedule;
use App\Modules\Settings\Models\DynamicListItem;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentScheduleTest extends TestCase
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

    private function recorder(): User
    {
        return $this->userWithPermissions(['versements.view', 'versements.record']);
    }

    public function test_a_schedule_reconciling_to_the_total_price_is_created(): void
    {
        $project = ClientProject::factory()->create(['total_price' => '3000.00']);
        Sanctum::actingAs($this->recorder());

        $this->putJson("/api/v1/projects/{$project->id}/schedule", [
            'installments' => [
                ['due_date' => '2026-08-01', 'amount' => '1000.00'],
                ['due_date' => '2026-09-01', 'amount' => '1000.00'],
                ['due_date' => '2026-10-01', 'amount' => '1000.00'],
            ],
        ])->assertOk()->assertJsonCount(3, 'data');

        $this->assertDatabaseCount('payment_schedules', 3);
        $this->assertDatabaseHas('payment_schedules', [
            'client_project_id' => $project->id, 'installment_no' => 1,
            'amount' => '1000.00', 'state' => 'pending', 'status' => 'active',
        ]);
    }

    public function test_a_schedule_that_does_not_reconcile_is_rejected(): void
    {
        $project = ClientProject::factory()->create(['total_price' => '3000.00']);
        Sanctum::actingAs($this->recorder());

        $this->putJson("/api/v1/projects/{$project->id}/schedule", [
            'installments' => [
                ['due_date' => '2026-08-01', 'amount' => '1000.00'],
                ['due_date' => '2026-09-01', 'amount' => '1500.00'],
            ],
        ])->assertStatus(422);

        $this->assertDatabaseCount('payment_schedules', 0);
    }

    public function test_a_schedule_cannot_be_created_without_a_total_price(): void
    {
        $project = ClientProject::factory()->create(['total_price' => null]);
        Sanctum::actingAs($this->recorder());

        $this->putJson("/api/v1/projects/{$project->id}/schedule", [
            'installments' => [['due_date' => '2026-08-01', 'amount' => '1000.00']],
        ])->assertStatus(422);
    }

    public function test_replacing_a_schedule_cancels_the_prior_active_plan(): void
    {
        $project = ClientProject::factory()->create(['total_price' => '2000.00']);
        Sanctum::actingAs($this->recorder());

        $this->putJson("/api/v1/projects/{$project->id}/schedule", [
            'installments' => [['due_date' => '2026-08-01', 'amount' => '2000.00']],
        ])->assertOk();

        $this->putJson("/api/v1/projects/{$project->id}/schedule", [
            'installments' => [
                ['due_date' => '2026-08-01', 'amount' => '1000.00'],
                ['due_date' => '2026-09-01', 'amount' => '1000.00'],
            ],
        ])->assertOk()->assertJsonCount(2, 'data');

        // Old plan kept but cancelled (no-delete); new plan active.
        $this->assertDatabaseCount('payment_schedules', 3);
        $this->assertEquals(2, $project->paymentSchedules()->active()->count());
        $this->assertEquals(1, PaymentSchedule::where('status', 'cancelled')->count());
    }

    public function test_replanning_with_recorded_payments_reallocates_what_was_paid(): void
    {
        // The client paid, then renegotiates how the remaining balance is
        // spread: the plan is replaced and the money already collected pours
        // back onto the new instalments in order.
        $project = ClientProject::factory()->create(['total_price' => '3000.00']);
        $cashier = $this->userWithPermissions(['versements.view', 'versements.record']);
        Sanctum::actingAs($cashier);

        $this->putJson("/api/v1/projects/{$project->id}/schedule", [
            'installments' => [
                ['due_date' => '2026-08-01', 'amount' => '1500.00'],
                ['due_date' => '2026-09-01', 'amount' => '1500.00'],
            ],
        ])->assertOk();

        $first = $project->paymentSchedules()->active()->orderBy('installment_no')->firstOrFail();
        $method = DynamicListItem::factory()->create();

        $this->postJson("/api/v1/projects/{$project->id}/versements", [
            'amount' => '1200.00', 'paid_on' => now()->toDateString(),
            'method_id' => $method->id, 'schedule_item_id' => $first->id,
        ])->assertCreated();

        // Re-plan: three instalments of 1000.00 — allowed even with money in.
        $this->putJson("/api/v1/projects/{$project->id}/schedule", [
            'installments' => [
                ['due_date' => '2026-08-01', 'amount' => '1000.00'],
                ['due_date' => '2026-09-01', 'amount' => '1000.00'],
                ['due_date' => '2026-10-01', 'amount' => '1000.00'],
            ],
        ])->assertOk();

        $plan = $project->paymentSchedules()->active()->orderBy('installment_no')->get();
        $this->assertCount(3, $plan);

        // 1200 paid -> first instalment full (paid), 200 on the second (partial).
        $this->assertSame('1000.00', (string) $plan[0]->paid_amount);
        $this->assertSame(ScheduleState::Paid, $plan[0]->state);
        $this->assertSame('200.00', (string) $plan[1]->paid_amount);
        $this->assertSame(ScheduleState::Partial, $plan[1]->state);
        $this->assertSame('0.00', (string) $plan[2]->paid_amount);
    }

    public function test_the_sweep_marks_past_due_unpaid_instalments_overdue(): void
    {
        $project = ClientProject::factory()->create(['total_price' => '1000.00']);
        $overdue = PaymentSchedule::factory()->create([
            'client_project_id' => $project->id,
            'due_date' => now()->subDay()->toDateString(),
            'state' => ScheduleState::Pending->value,
        ]);
        $future = PaymentSchedule::factory()->create([
            'client_project_id' => $project->id,
            'due_date' => now()->addMonth()->toDateString(),
            'state' => ScheduleState::Pending->value,
        ]);

        $flipped = app(MarkSchedulesOverdue::class)->handle();

        $this->assertSame(1, $flipped);
        $this->assertSame(ScheduleState::Overdue, $overdue->refresh()->state);
        $this->assertSame(ScheduleState::Pending, $future->refresh()->state);
    }

    public function test_recording_a_schedule_requires_the_record_permission(): void
    {
        $project = ClientProject::factory()->create(['total_price' => '1000.00']);
        Sanctum::actingAs($this->userWithPermissions(['versements.view']));

        $this->putJson("/api/v1/projects/{$project->id}/schedule", [
            'installments' => [['due_date' => '2026-08-01', 'amount' => '1000.00']],
        ])->assertForbidden();
    }
}
