<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Clients\Actions\CloseDealUnit;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Clients\Models\Deal;
use App\Modules\Clients\Models\DealItem;
use App\Modules\Collaboration\Notifications\DomainNotification;
use App\Modules\Inventory\Actions\ExpireReservedUnits;
use App\Modules\Inventory\Actions\ReserveUnit;
use App\Modules\Inventory\Enums\SaleStatus;
use App\Modules\Inventory\Models\Reservation;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Payments\Actions\RecordVersement;
use App\Modules\Settings\Models\DynamicListItem;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The reservation QUEUE: ordered "you are Nth in line" holds behind a Reserved
 * unit, promotion of the next project when the deposit lock lifts, targeted
 * cancellation notices when a sale ends the queue, and the follow-up board
 * endpoint (with its identity masking).
 */
class ReservationQueueTest extends TestCase
{
    use RefreshDatabase;

    /** @param  list<string>  $slugs */
    private function userWithPermissions(array $slugs): User
    {
        $role = Role::factory()->create();
        $ids = collect($slugs)->map(
            fn (string $slug) => Permission::firstOrCreate(['slug' => $slug], ['name' => $slug])->id,
        );
        $role->permissions()->sync($ids);

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function reserve(Unit $unit, ClientProject $project, User $agent): Reservation
    {
        return app(ReserveUnit::class)->handle(
            $unit,
            ['client_project_id' => $project->id, 'no_expiry' => true],
            $agent,
        );
    }

    private function deposit(ClientProject $project, Unit $unit, User $agent): void
    {
        app(RecordVersement::class)->handle($project, [
            'unit_id' => $unit->id,
            'amount' => '50000.00',
            'paid_on' => now()->toDateString(),
            'method_id' => DynamicListItem::factory()->create()->id,
        ], $agent);
    }

    public function test_the_queue_puts_the_deposit_holder_first_then_backups_oldest_first(): void
    {
        $agent = User::factory()->create();
        $unit = Unit::factory()->create(['sale_status' => 'available']);
        $a = ClientProject::factory()->create();
        $b = ClientProject::factory()->create();
        $c = ClientProject::factory()->create();

        // B held first, C second — then A holds LAST but pays the deposit.
        Carbon::setTestNow(now());
        $this->reserve($unit, $b, $agent);
        Carbon::setTestNow(now()->addMinutes(5));
        $this->reserve($unit, $c, $agent);
        Carbon::setTestNow(now()->addMinutes(5));
        $this->reserve($unit, $a, $agent);
        $this->deposit($a, $unit, $agent);
        Carbon::setTestNow();

        $queue = $unit->fresh()->reservationQueue();

        $this->assertSame(
            [$a->id, $b->id, $c->id],
            $queue->pluck('client_project_id')->map(fn ($id) => (int) $id)->all(),
        );
    }

    public function test_a_lapsed_deposit_promotes_the_next_in_line_with_a_notification(): void
    {
        Notification::fake();

        $agent = User::factory()->create();
        $nextAgent = User::factory()->create();
        $unit = Unit::factory()->create(['sale_status' => 'available']);
        $a = ClientProject::factory()->create(['created_by' => $agent->id]);
        $b = ClientProject::factory()->create(['created_by' => $nextAgent->id]);

        $this->reserve($unit, $a, $agent);
        $this->deposit($a, $unit, $agent);
        $this->reserve($unit, $b, $nextAgent);

        Carbon::setTestNow(now()->addHours(100));
        app(ExpireReservedUnits::class)->handle();
        Carbon::setTestNow();

        // B's team is told it is now first in line.
        Notification::assertSentTo(
            $nextAgent,
            DomainNotification::class,
            fn (DomainNotification $n) => $n->kind === 'reservation_next'
                && $n->subjectId === $b->id,
        );
    }

    public function test_losing_the_holding_deal_promotes_the_next_in_line(): void
    {
        Notification::fake();

        $agent = User::factory()->create();
        $nextAgent = User::factory()->create();
        $unit = Unit::factory()->create(['sale_status' => 'available']);
        $a = ClientProject::factory()->create(['created_by' => $agent->id]);
        $b = ClientProject::factory()->create(['created_by' => $nextAgent->id]);

        $this->reserve($unit, $a, $agent);
        $this->deposit($a, $unit, $agent);
        $this->reserve($unit, $b, $nextAgent);

        $deal = Deal::factory()->create(['client_project_id' => $a->id]);
        $item = DealItem::factory()->create(['deal_id' => $deal->id, 'unit_id' => $unit->id])->refresh();

        app(CloseDealUnit::class)->handle($item, 'lost');

        // The deposit lock is gone; the unit falls back to interested (B holds).
        $this->assertSame(SaleStatus::Interested, $unit->fresh()->sale_status);
        Notification::assertSentTo(
            $nextAgent,
            DomainNotification::class,
            fn (DomainNotification $n) => $n->kind === 'reservation_next'
                && $n->subjectId === $b->id,
        );
    }

    public function test_a_sale_cancels_every_queued_reservation_with_its_position(): void
    {
        Notification::fake();

        $agent = User::factory()->create();
        $agentB = User::factory()->create();
        $agentC = User::factory()->create();
        $unit = Unit::factory()->create(['sale_status' => 'available']);
        $a = ClientProject::factory()->create(['created_by' => $agent->id]);
        $b = ClientProject::factory()->create(['created_by' => $agentB->id]);
        $c = ClientProject::factory()->create(['created_by' => $agentC->id]);

        Carbon::setTestNow(now());
        $this->reserve($unit, $a, $agent);
        Carbon::setTestNow(now()->addMinutes(5));
        $this->reserve($unit, $b, $agentB);
        Carbon::setTestNow(now()->addMinutes(5));
        $this->reserve($unit, $c, $agentC);
        Carbon::setTestNow();
        $this->deposit($a, $unit, $agent);

        // B rides an open deal; C is a pure interest hold (no deal).
        $dealA = Deal::factory()->create(['client_project_id' => $a->id]);
        $itemA = DealItem::factory()->create(['deal_id' => $dealA->id, 'unit_id' => $unit->id])->refresh();
        $dealB = Deal::factory()->create(['client_project_id' => $b->id]);
        DealItem::factory()->create(['deal_id' => $dealB->id, 'unit_id' => $unit->id]);

        app(CloseDealUnit::class)->handle($itemA, 'won', '900000.00');

        // Every queued hold ended with the sale — including C's deal-less one.
        $this->assertSame(
            0,
            Reservation::query()->where('unit_id', $unit->id)
                ->whereIn('client_project_id', [$b->id, $c->id])
                ->where('hold_status', 'active')->count(),
        );

        // Each team is told the place its client held when the unit sold.
        Notification::assertSentTo(
            $agentB,
            DomainNotification::class,
            fn (DomainNotification $n) => $n->kind === 'reservation_cancelled'
                && str_contains($n->body, '#2'),
        );
        Notification::assertSentTo(
            $agentC,
            DomainNotification::class,
            fn (DomainNotification $n) => $n->kind === 'reservation_cancelled'
                && str_contains($n->body, '#3'),
        );
    }

    public function test_the_queues_endpoint_requires_units_view(): void
    {
        Sanctum::actingAs($this->userWithPermissions(['clients.view']));

        $this->getJson('/api/v1/reservations/queues')->assertForbidden();
    }

    public function test_the_queues_board_orders_the_queue_and_masks_invisible_projects(): void
    {
        $me = $this->userWithPermissions(['units.view']);
        $other = User::factory()->create();
        $unit = Unit::factory()->create(['sale_status' => 'available']);

        // The deposit holder belongs to another agent; my project queues 2nd.
        $theirs = ClientProject::factory()->create(['created_by' => $other->id]);
        $mine = ClientProject::factory()->create(['created_by' => $me->id]);

        Carbon::setTestNow(now());
        $this->reserve($unit, $theirs, $other);
        $this->deposit($theirs, $unit, $other);
        Carbon::setTestNow(now()->addMinutes(5));
        $this->reserve($unit, $mine, $me);
        Carbon::setTestNow();

        Sanctum::actingAs($me);

        $response = $this->getJson('/api/v1/reservations/queues')->assertOk();
        $rows = collect($response->json('data'));
        $row = $rows->firstWhere('id', $unit->id);

        $this->assertNotNull($row);
        $this->assertSame('reserved', $row['sale_status']);
        $this->assertCount(2, $row['queue']);

        // #1 = the deposit holder — another agent's client, masked.
        $this->assertSame(1, $row['queue'][0]['position']);
        $this->assertTrue($row['queue'][0]['is_deposit']);
        $this->assertFalse($row['queue'][0]['visible']);
        $this->assertNull($row['queue'][0]['client_name']);

        // #2 = my own client — fully visible.
        $this->assertSame(2, $row['queue'][1]['position']);
        $this->assertTrue($row['queue'][1]['visible']);
        $this->assertSame($mine->id, $row['queue'][1]['client_project_id']);
    }
}
