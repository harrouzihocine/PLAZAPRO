<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Modules\Clients\Models\ClientProject;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Payments\Enums\ScheduleState;
use App\Modules\Payments\Models\PaymentSchedule;
use App\Modules\Payments\Models\Versement;
use App\Modules\Payments\Support\Money;
use App\Modules\Settings\Models\DynamicListItem;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VersementTest extends TestCase
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

    private function cashier(): User
    {
        return $this->userWithPermissions(['versements.view', 'versements.record', 'versements.cancel']);
    }

    private function method(): DynamicListItem
    {
        return DynamicListItem::factory()->create();
    }

    public function test_recording_a_versement_allocates_to_its_schedule_item(): void
    {
        $project = ClientProject::factory()->create(['total_price' => '2000.00']);
        $item = PaymentSchedule::factory()->create([
            'client_project_id' => $project->id, 'amount' => '2000.00',
            'due_date' => now()->addMonth()->toDateString(),
        ]);
        Sanctum::actingAs($this->cashier());

        $this->postJson("/api/v1/projects/{$project->id}/versements", [
            'amount' => '800.00',
            'paid_on' => now()->toDateString(),
            'method_id' => $this->method()->id,
            'schedule_item_id' => $item->id,
        ])->assertCreated()->assertJsonPath('data.amount', '800.00');

        $item->refresh();
        $this->assertSame('800.00', (string) $item->paid_amount);
        $this->assertSame(ScheduleState::Partial, $item->state);
    }

    public function test_paying_an_instalment_in_full_marks_it_paid(): void
    {
        $project = ClientProject::factory()->create(['total_price' => '1000.00']);
        $item = PaymentSchedule::factory()->create([
            'client_project_id' => $project->id, 'amount' => '1000.00',
            'due_date' => now()->addMonth()->toDateString(),
        ]);
        Sanctum::actingAs($this->cashier());

        $this->postJson("/api/v1/projects/{$project->id}/versements", [
            'amount' => '1000.00',
            'paid_on' => now()->toDateString(),
            'method_id' => $this->method()->id,
            'schedule_item_id' => $item->id,
        ])->assertCreated();

        $item->refresh();
        $this->assertSame('1000.00', (string) $item->paid_amount);
        $this->assertSame(ScheduleState::Paid, $item->state);
    }

    public function test_a_deposit_reserved_window_must_be_in_the_future(): void
    {
        $project = ClientProject::factory()->create(['total_price' => '2000.00']);
        Sanctum::actingAs($this->cashier());

        $this->postJson("/api/v1/projects/{$project->id}/versements", [
            'amount' => '500.00',
            'paid_on' => now()->toDateString(),
            'method_id' => $this->method()->id,
            'reserved_until' => now()->subHour()->toDateTimeString(),
        ])->assertUnprocessable()->assertJsonValidationErrors('reserved_until');
    }

    public function test_the_holder_can_move_its_hold_deadline_without_a_payment(): void
    {
        $project = ClientProject::factory()->create();
        $unit = Unit::factory()->create([
            'sale_status' => 'reserved',
            'reserved_project_id' => $project->id,
            'reserved_expires_at' => now()->addHours(6),
        ]);
        Sanctum::actingAs($this->cashier());

        $moved = now()->addDays(2)->startOfMinute();
        $this->patchJson("/api/v1/projects/{$project->id}/units/{$unit->id}/reserved-until", [
            'reserved_until' => $moved->toDateTimeString(),
        ])->assertOk();

        $this->assertTrue($moved->equalTo($unit->fresh()->reserved_expires_at));
    }

    public function test_a_hold_deadline_cannot_be_moved_by_another_project(): void
    {
        $holder = ClientProject::factory()->create();
        $other = ClientProject::factory()->create();
        $unit = Unit::factory()->create([
            'sale_status' => 'reserved',
            'reserved_project_id' => $holder->id,
            'reserved_expires_at' => now()->addHours(6),
        ]);
        Sanctum::actingAs($this->cashier());

        $this->patchJson("/api/v1/projects/{$other->id}/units/{$unit->id}/reserved-until", [
            'reserved_until' => now()->addDays(2)->toDateTimeString(),
        ])->assertUnprocessable();
    }

    public function test_a_hold_deadline_needs_a_reserved_unit(): void
    {
        $project = ClientProject::factory()->create();
        $unit = Unit::factory()->create(['sale_status' => 'available']);
        Sanctum::actingAs($this->cashier());

        $this->patchJson("/api/v1/projects/{$project->id}/units/{$unit->id}/reserved-until", [
            'reserved_until' => now()->addDays(2)->toDateTimeString(),
        ])->assertUnprocessable();
    }

    public function test_moving_a_hold_deadline_requires_versements_record(): void
    {
        $project = ClientProject::factory()->create();
        $unit = Unit::factory()->create([
            'sale_status' => 'reserved',
            'reserved_project_id' => $project->id,
            'reserved_expires_at' => now()->addHours(6),
        ]);
        Sanctum::actingAs($this->userWithPermissions(['versements.view']));

        $this->patchJson("/api/v1/projects/{$project->id}/units/{$unit->id}/reserved-until", [
            'reserved_until' => now()->addDays(2)->toDateTimeString(),
        ])->assertForbidden();
    }

    public function test_the_running_balance_is_computed_server_side(): void
    {
        $project = ClientProject::factory()->create(['total_price' => '2000.00']);
        $method = $this->method()->id;
        Sanctum::actingAs($this->cashier());

        $this->postJson("/api/v1/projects/{$project->id}/versements", [
            'amount' => '500.00', 'paid_on' => now()->toDateString(), 'method_id' => $method,
        ])->assertCreated();
        $this->postJson("/api/v1/projects/{$project->id}/versements", [
            'amount' => '300.00', 'paid_on' => now()->toDateString(), 'method_id' => $method,
        ])->assertCreated();

        $this->getJson("/api/v1/projects/{$project->id}/versements")
            ->assertOk()
            ->assertJsonPath('meta.total_price', '2000.00')
            ->assertJsonPath('meta.total_paid', '800.00')
            ->assertJsonPath('meta.balance', '1200.00');
    }

    public function test_versement_rounding_is_exact_across_a_full_schedule(): void
    {
        // 1000 / 3 does not divide evenly — the plan must still reconcile exactly.
        $project = ClientProject::factory()->create(['total_price' => '1000.00']);
        $amounts = ['333.33', '333.33', '333.34'];
        $method = $this->method()->id;
        Sanctum::actingAs($this->cashier());

        $this->putJson("/api/v1/projects/{$project->id}/schedule", [
            'installments' => [
                ['due_date' => now()->addMonth()->toDateString(), 'amount' => $amounts[0]],
                ['due_date' => now()->addMonths(2)->toDateString(), 'amount' => $amounts[1]],
                ['due_date' => now()->addMonths(3)->toDateString(), 'amount' => $amounts[2]],
            ],
        ])->assertOk();

        foreach ($project->paymentSchedules()->active()->orderBy('installment_no')->get() as $i => $item) {
            $this->postJson("/api/v1/projects/{$project->id}/versements", [
                'amount' => $amounts[$i], 'paid_on' => now()->toDateString(),
                'method_id' => $method, 'schedule_item_id' => $item->id,
            ])->assertCreated();
        }

        // Every instalment fully paid, and the sum reconciles to the penny.
        $this->assertSame(3, $project->paymentSchedules()->active()->where('state', 'paid')->count());
        $paid = Money::sum($project->versements()->active()->pluck('amount'));
        $this->assertSame('1000.00', $paid);
    }

    public function test_correcting_a_versement_supersedes_it_and_reallocates(): void
    {
        $project = ClientProject::factory()->create(['total_price' => '2000.00']);
        $item = PaymentSchedule::factory()->create([
            'client_project_id' => $project->id, 'amount' => '2000.00',
            'due_date' => now()->addMonth()->toDateString(),
        ]);
        $method = $this->method()->id;
        Sanctum::actingAs($this->cashier());

        $original = $this->postJson("/api/v1/projects/{$project->id}/versements", [
            'amount' => '1000.00', 'paid_on' => now()->toDateString(),
            'method_id' => $method, 'schedule_item_id' => $item->id,
        ])->assertCreated()->json('data.id');

        $this->assertSame('1000.00', (string) $item->refresh()->paid_amount);

        $this->postJson("/api/v1/versements/{$original}/correct", [
            'amount' => '800.00', 'paid_on' => now()->toDateString(),
            'method_id' => $method, 'reason' => 'Wrong amount keyed',
        ])->assertCreated()->assertJsonPath('data.amount', '800.00')
            ->assertJsonPath('data.supersedes_id', $original);

        // Original cancelled, replacement active — BOTH persist (history).
        $this->assertDatabaseHas('versements', ['id' => $original, 'status' => 'cancelled']);
        $this->assertSame(2, Versement::count());
        $this->assertSame(1, $project->versements()->active()->count());

        // Re-allocation: the instalment now reflects the corrected 800.00.
        $this->assertSame('800.00', (string) $item->refresh()->paid_amount);
        $this->assertSame(ScheduleState::Partial, $item->state);
    }

    public function test_a_cancelled_versement_cannot_be_corrected_again(): void
    {
        $project = ClientProject::factory()->create(['total_price' => '1000.00']);
        $versement = Versement::factory()->create([
            'client_project_id' => $project->id, 'status' => 'cancelled',
        ]);
        Sanctum::actingAs($this->cashier());

        $this->postJson("/api/v1/versements/{$versement->id}/correct", [
            'amount' => '500.00', 'paid_on' => now()->toDateString(),
            'method_id' => $this->method()->id, 'reason' => 'x',
        ])->assertStatus(422);
    }

    public function test_refunding_a_versement_keeps_it_as_history_and_reverses_the_allocation(): void
    {
        $project = ClientProject::factory()->create(['total_price' => '2000.00']);
        $item = PaymentSchedule::factory()->create([
            'client_project_id' => $project->id, 'amount' => '2000.00',
            'due_date' => now()->addMonth()->toDateString(),
        ]);
        $method = $this->method()->id;
        Sanctum::actingAs($this->cashier());

        $id = $this->postJson("/api/v1/projects/{$project->id}/versements", [
            'amount' => '1000.00', 'paid_on' => now()->toDateString(),
            'method_id' => $method, 'schedule_item_id' => $item->id,
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/versements/{$id}/refund", ['reason' => 'Deal released — money returned'])
            ->assertOk()
            ->assertJsonPath('data.refund_reason', 'Deal released — money returned');

        // The row stays ACTIVE in history — flagged, not cancelled or superseded.
        $versement = Versement::query()->findOrFail($id);
        $this->assertSame('active', $versement->status->value);
        $this->assertTrue($versement->isRefunded());

        // Its contribution to the instalment is reversed…
        $this->assertSame('0.00', (string) $item->refresh()->paid_amount);

        // …and it no longer counts toward the balance (still listed, though).
        $index = $this->getJson("/api/v1/projects/{$project->id}/versements")->assertOk()->json();
        $this->assertSame('0.00', $index['meta']['total_paid']);
        $this->assertCount(1, $index['data']);

        // Refund is final for the row: no double refund, no correction after it.
        $this->postJson("/api/v1/versements/{$id}/refund", ['reason' => 'again'])->assertStatus(422);
        $this->postJson("/api/v1/versements/{$id}/correct", [
            'amount' => '500.00', 'paid_on' => now()->toDateString(),
            'method_id' => $method, 'reason' => 'x',
        ])->assertStatus(422);
    }

    public function test_refund_requires_the_cancel_permission(): void
    {
        $project = ClientProject::factory()->create(['total_price' => '1000.00']);
        $versement = Versement::factory()->create(['client_project_id' => $project->id]);
        Sanctum::actingAs($this->userWithPermissions(['versements.view', 'versements.record']));

        $this->postJson("/api/v1/versements/{$versement->id}/refund", ['reason' => 'x'])
            ->assertForbidden();
    }

    public function test_recording_requires_the_record_permission(): void
    {
        $project = ClientProject::factory()->create(['total_price' => '1000.00']);
        Sanctum::actingAs($this->userWithPermissions(['versements.view']));

        $this->postJson("/api/v1/projects/{$project->id}/versements", [
            'amount' => '100.00', 'paid_on' => now()->toDateString(), 'method_id' => $this->method()->id,
        ])->assertForbidden();
    }
}
