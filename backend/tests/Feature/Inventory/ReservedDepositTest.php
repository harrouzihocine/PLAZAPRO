<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Clients\Actions\CloseDealUnit;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Clients\Models\Deal;
use App\Modules\Clients\Models\DealItem;
use App\Modules\Inventory\Actions\ExpireReservedUnits;
use App\Modules\Inventory\Actions\ReserveUnit;
use App\Modules\Inventory\Enums\SaleStatus;
use App\Modules\Inventory\Events\ReservedLapsed;
use App\Modules\Inventory\Events\UnitSold;
use App\Modules\Inventory\Models\Reservation;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Payments\Actions\RecordVersement;
use App\Modules\Settings\Models\AppSetting;
use App\Modules\Settings\Models\DynamicListItem;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * The Reserved lifecycle: a holding deposit takes a not-yet-sold unit off the
 * market (for everyone but backups), it lapses back to the market on the sweeper,
 * and a sale ends every backup.
 */
class ReservedDepositTest extends TestCase
{
    use RefreshDatabase;

    private function reserve(Unit $unit, ClientProject $project, User $agent): Reservation
    {
        return app(ReserveUnit::class)->handle(
            $unit,
            ['client_project_id' => $project->id, 'no_expiry' => true],
            $agent,
        );
    }

    private function deposit(ClientProject $project, Unit $unit, User $agent, string $amount = '50000.00', ?string $reservedUntil = null): void
    {
        app(RecordVersement::class)->handle($project, [
            'unit_id' => $unit->id,
            'amount' => $amount,
            'paid_on' => now()->toDateString(),
            'method_id' => DynamicListItem::factory()->create()->id,
            'reserved_until' => $reservedUntil,
        ], $agent);
    }

    public function test_a_deposit_makes_an_interested_unit_reserved(): void
    {
        $agent = User::factory()->create();
        $unit = Unit::factory()->create(['sale_status' => 'available']);
        $project = ClientProject::factory()->create();
        $this->reserve($unit, $project, $agent);

        $this->deposit($project, $unit, $agent);

        $unit->refresh();
        $this->assertSame(SaleStatus::Reserved, $unit->sale_status);
        $this->assertSame($project->id, (int) $unit->reserved_project_id);
        $this->assertNotNull($unit->reserved_expires_at);
    }

    public function test_a_deposit_can_set_its_own_reserved_window(): void
    {
        $agent = User::factory()->create();
        $unit = Unit::factory()->create(['sale_status' => 'available']);
        $project = ClientProject::factory()->create();
        $this->reserve($unit, $project, $agent);

        // The agent picked the exact back-to-market moment for THIS deal.
        $deadline = now()->addHours(6)->startOfMinute();
        $this->deposit($project, $unit, $agent, '50000.00', $deadline->toDateTimeString());

        $this->assertTrue($deadline->equalTo($unit->fresh()->reserved_expires_at));
    }

    public function test_the_default_window_comes_from_the_app_setting(): void
    {
        $frozen = now()->startOfSecond();
        Carbon::setTestNow($frozen);
        AppSetting::set('reserved_hold_hours', '10');
        $agent = User::factory()->create();
        $unit = Unit::factory()->create(['sale_status' => 'available']);
        $project = ClientProject::factory()->create();
        $this->reserve($unit, $project, $agent);

        $this->deposit($project, $unit, $agent);
        Carbon::setTestNow();

        $this->assertTrue($frozen->copy()->addHours(10)->equalTo($unit->fresh()->reserved_expires_at));
    }

    public function test_a_later_payment_never_moves_the_deadline(): void
    {
        $agent = User::factory()->create();
        $unit = Unit::factory()->create(['sale_status' => 'available']);
        $project = ClientProject::factory()->create();
        $this->reserve($unit, $project, $agent);

        $deadline = now()->addHours(6)->startOfMinute();
        $this->deposit($project, $unit, $agent, '50000.00', $deadline->toDateTimeString());
        // A second instalment tries a later window — the armed deadline stands.
        $this->deposit($project, $unit, $agent, '10000.00', now()->addDays(30)->toDateTimeString());

        $this->assertTrue($deadline->equalTo($unit->fresh()->reserved_expires_at));
    }

    public function test_a_backup_can_reserve_but_not_deposit_on_a_held_unit(): void
    {
        $agent = User::factory()->create();
        $unit = Unit::factory()->create(['sale_status' => 'available']);
        $a = ClientProject::factory()->create();
        $b = ClientProject::factory()->create();

        $this->reserve($unit, $a, $agent);
        $this->deposit($a, $unit, $agent);

        // B queues as a backup (2nd place) — marking interest in a reserved unit is fine.
        $this->reserve($unit, $b, $agent);
        $this->assertSame(2, $unit->fresh()->interestedCount());

        // But B cannot pay a deposit on a unit held by A.
        $this->expectException(HttpException::class);
        $this->deposit($b, $unit, $agent, '10000.00');
    }

    public function test_the_sweeper_returns_a_lapsed_reservation_to_interested_with_backups(): void
    {
        Event::fake([ReservedLapsed::class, UnitSold::class]);
        $agent = User::factory()->create();
        $unit = Unit::factory()->create(['sale_status' => 'available']);
        $a = ClientProject::factory()->create();
        $b = ClientProject::factory()->create();

        $this->reserve($unit, $a, $agent);
        $this->reserve($unit, $b, $agent);
        $this->deposit($a, $unit, $agent);
        $this->assertSame(SaleStatus::Reserved, $unit->fresh()->sale_status);

        Carbon::setTestNow(now()->addHours(100));
        app(ExpireReservedUnits::class)->handle();
        Carbon::setTestNow();

        $unit->refresh();
        $this->assertSame(SaleStatus::Interested, $unit->sale_status);
        $this->assertNull($unit->reserved_expires_at);
        $this->assertSame(1, $unit->interestedCount()); // only B's backup remains
        Event::assertDispatched(ReservedLapsed::class);
    }

    public function test_the_sweeper_frees_a_lapsed_hold_with_no_backups(): void
    {
        $agent = User::factory()->create();
        $unit = Unit::factory()->create(['sale_status' => 'available']);
        $a = ClientProject::factory()->create();

        $this->reserve($unit, $a, $agent);
        $this->deposit($a, $unit, $agent);

        Carbon::setTestNow(now()->addHours(100));
        app(ExpireReservedUnits::class)->handle();
        Carbon::setTestNow();

        $this->assertSame(SaleStatus::Available, $unit->fresh()->sale_status);
    }

    public function test_winning_a_unit_sells_it_and_releases_every_backup(): void
    {
        Event::fake([UnitSold::class]);
        $agent = User::factory()->create();
        $unit = Unit::factory()->create(['sale_status' => 'available']);
        $a = ClientProject::factory()->create();
        $b = ClientProject::factory()->create();

        $this->reserve($unit, $a, $agent);
        $this->reserve($unit, $b, $agent);
        $dealA = Deal::factory()->create(['client_project_id' => $a->id]);
        // refresh() loads the DB-default state ('open') onto the instance.
        $itemA = DealItem::factory()->create(['deal_id' => $dealA->id, 'unit_id' => $unit->id])->refresh();
        $dealB = Deal::factory()->create(['client_project_id' => $b->id]);
        $itemB = DealItem::factory()->create(['deal_id' => $dealB->id, 'unit_id' => $unit->id]);

        app(CloseDealUnit::class)->handle($itemA, 'won', '900000.00');

        $this->assertSame(SaleStatus::Sold, $unit->fresh()->sale_status);
        // The sale ended B's backup — its item is lost and its hold released.
        $this->assertSame('lost', $itemB->fresh()->state->value);
        $this->assertSame(
            0,
            Reservation::query()->where('unit_id', $unit->id)
                ->where('client_project_id', $b->id)
                ->where('hold_status', 'active')->count(),
        );
        Event::assertDispatched(UnitSold::class);
    }

    public function test_winning_records_the_credited_agents_and_broadcasts_them(): void
    {
        Event::fake([UnitSold::class]);
        $agent = User::factory()->create();
        $saleAgent = User::factory()->create(['name' => 'Sale Sam']);
        $insiteAgent = User::factory()->create(['name' => 'Site Sara']);
        $unit = Unit::factory()->create(['sale_status' => 'available']);
        $project = ClientProject::factory()->create();
        $this->reserve($unit, $project, $agent);

        $deal = Deal::factory()->create(['client_project_id' => $project->id]);
        $item = DealItem::factory()->create(['deal_id' => $deal->id, 'unit_id' => $unit->id])->refresh();

        app(CloseDealUnit::class)->handle($item, 'won', '900000.00', null, null, [
            'sale' => [$saleAgent->id],
            'insite' => [$insiteAgent->id],
            'other' => [],
        ]);

        $item->refresh();
        $this->assertSame([$saleAgent->id], $item->credits['sale']);
        $this->assertSame([$insiteAgent->id], $item->credits['insite']);

        Event::assertDispatched(
            UnitSold::class,
            fn (UnitSold $e) => $e->saleAgents === ['Sale Sam'] && $e->insiteAgents === ['Site Sara'],
        );
    }
}
