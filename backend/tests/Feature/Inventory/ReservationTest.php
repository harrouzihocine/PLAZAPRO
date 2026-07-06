<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Actions\ExpireReservationHolds;
use App\Modules\Inventory\Models\Reservation;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReservationTest extends TestCase
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

    private function agent(): User
    {
        return $this->userWithPermissions(['units.view', 'units.interest']);
    }

    public function test_reserving_an_available_unit_holds_it_for_48h(): void
    {
        $unit = Unit::factory()->create(['sale_status' => 'available']);
        Sanctum::actingAs($this->agent());

        $this->postJson("/api/v1/units/{$unit->id}/interest")
            ->assertCreated()
            ->assertJsonPath('data.hold_status', 'active');

        $unit->refresh();
        $this->assertSame('interested', $unit->sale_status->value);

        $reservation = Reservation::latest('id')->first();
        $this->assertSame(48, (int) round($reservation->held_at->diffInHours($reservation->expires_at)));
    }

    public function test_marking_interest_in_an_interested_unit_adds_a_backup(): void
    {
        // Interest holds are multi-project: an interested unit can be held again
        // as a backup ("2nd place"). It stays interested and the count grows.
        $unit = Unit::factory()->interested()->create();
        Sanctum::actingAs($this->agent());

        $this->postJson("/api/v1/units/{$unit->id}/interest")->assertCreated();
        $this->assertSame('interested', $unit->fresh()->sale_status->value);
        $this->assertSame(1, Reservation::count());
    }

    public function test_reserving_a_sold_unit_is_rejected(): void
    {
        $unit = Unit::factory()->create(['sale_status' => 'sold']);
        Sanctum::actingAs($this->agent());

        $this->postJson("/api/v1/units/{$unit->id}/interest")->assertStatus(422);
        $this->assertSame(0, Reservation::count());
    }

    public function test_the_sweeper_expires_holds_past_48h_and_frees_the_unit(): void
    {
        $unit = Unit::factory()->create(['sale_status' => 'available']);
        Sanctum::actingAs($this->agent());
        $this->postJson("/api/v1/units/{$unit->id}/interest")->assertCreated();

        // Jump 49 hours forward; the hold is now past its window.
        Carbon::setTestNow(now()->addHours(49));
        $expired = app(ExpireReservationHolds::class)->handle();

        $this->assertSame(1, $expired);
        $this->assertSame('expired', Reservation::latest('id')->first()->hold_status->value);
        $this->assertSame('available', $unit->fresh()->sale_status->value);

        Carbon::setTestNow();
    }

    public function test_a_hold_within_48h_is_not_expired(): void
    {
        $unit = Unit::factory()->create(['sale_status' => 'available']);
        Sanctum::actingAs($this->agent());
        $this->postJson("/api/v1/units/{$unit->id}/interest")->assertCreated();

        Carbon::setTestNow(now()->addHours(47));
        $this->assertSame(0, app(ExpireReservationHolds::class)->handle());
        $this->assertSame('interested', $unit->fresh()->sale_status->value);
        Carbon::setTestNow();
    }

    public function test_releasing_a_hold_returns_the_unit_to_available(): void
    {
        $unit = Unit::factory()->create(['sale_status' => 'available']);
        Sanctum::actingAs($this->agent());
        $id = $this->postJson("/api/v1/units/{$unit->id}/interest")->json('data.id');

        $this->postJson("/api/v1/reservations/{$id}/release")
            ->assertOk()
            ->assertJsonPath('data.hold_status', 'released');

        $this->assertSame('available', $unit->fresh()->sale_status->value);
    }

    public function test_converting_a_hold_marks_the_unit_sold(): void
    {
        $unit = Unit::factory()->create(['sale_status' => 'available']);
        Sanctum::actingAs($this->agent());
        $id = $this->postJson("/api/v1/units/{$unit->id}/interest")->json('data.id');

        $this->postJson("/api/v1/reservations/{$id}/convert")
            ->assertOk()
            ->assertJsonPath('data.hold_status', 'converted');

        $this->assertSame('sold', $unit->fresh()->sale_status->value);
    }

    public function test_marking_interest_requires_units_interest_permission(): void
    {
        $unit = Unit::factory()->create(['sale_status' => 'available']);
        Sanctum::actingAs($this->userWithPermissions(['units.view']));

        $this->postJson("/api/v1/units/{$unit->id}/interest")->assertForbidden();
    }

    public function test_holds_expire_command_runs(): void
    {
        $this->artisan('holds:expire')->assertSuccessful();
    }
}
