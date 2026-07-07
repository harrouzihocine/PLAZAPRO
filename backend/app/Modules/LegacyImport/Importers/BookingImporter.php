<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\Importers;

use App\Modules\Inventory\Models\Reservation;
use App\Modules\LegacyImport\Support\BaseImporter;

/**
 * §5.12 — booking_reports fan out per booked unit into reservations
 * (converted when accepted — all 17 accepted have a payment; expired for the
 * two stale pendings), a won deal + deal_items for accepted bookings
 * (synth:deal_from_booking / synth:deal_item_from_booked), a synthesized
 * shortlist row per booked unit (synth:shortlist_from_booked) to keep the
 * shortlist→deal narrative coherent, and extending_reports → activity_log on
 * the reservation (FQCN morph — activity_log uses class names, §2.4).
 * Bookings without any booked unit (#22 #23 #38 #43) create item-less deals
 * + a ledger warning.
 */
class BookingImporter extends BaseImporter
{
    /** @var array<int, list<object>> booking id → booked_estate rows */
    private array $bookedByBooking = [];

    /** @var array<int, string> booking id → first payment_date */
    private array $paymentDates = [];

    /** @var array<int, float|string|null> legacy estate id → raw legacy price */
    private array $estatePrices = [];

    /** @var array<int, int> booking id → first reservation target id (for extendings) */
    private array $firstReservation = [];

    public function phase(): string
    {
        return 'bookings';
    }

    protected function sourceTable(): string
    {
        return 'booking_reports';
    }

    protected function targetTable(): string
    {
        return 'reservations';
    }

    protected function map(object $row): ?array
    {
        return null; // unused — custom run()
    }

    public function run(): void
    {
        foreach (['booked_estate', 'booking_reports', 'extending_reports', 'synth:deal_from_booking', 'synth:deal_item_from_booked', 'synth:shortlist_from_booked', 'projects', 'users', 'estates', 'visit_reports'] as $table) {
            $this->ctx->preloadMap($table);
        }

        $this->bookedByBooking = $this->ctx->legacy->table('booked_estate')
            ->whereNotNull('booking_report_id')->orderBy('id')->get()
            ->groupBy('booking_report_id')->map(fn ($rows) => $rows->all())->all();
        $this->paymentDates = $this->ctx->legacy->table('payment_reports')
            ->whereNotNull('booking_report_id')->orderBy('id')
            ->get(['booking_report_id', 'payment_date'])
            ->groupBy('booking_report_id')->map(fn ($rows) => $rows->first()->payment_date)->all();
        $this->estatePrices = $this->ctx->legacy->table('estates')->pluck('price', 'id')->all();

        $bookings = $this->ctx->legacy->table('booking_reports')->orderBy('id')->get();
        $this->ctx->target->transaction(function () use ($bookings): void {
            foreach ($bookings as $booking) {
                $this->syncBooking($booking);
            }
            $this->syncExtendings();
        });
    }

    private function syncBooking(object $booking): void
    {
        $accepted = $booking->status === 'accepted';
        if (! $accepted && $booking->status !== 'pending') {
            $this->ctx->warn('booking_unexpected_status', "Booking #{$booking->id} has status '{$booking->status}' — treated as pending (expired hold, no deal).");
        }

        $bookedRows = $this->bookedByBooking[(int) $booking->id] ?? [];
        $projectId = $this->ctx->requireMapId('projects', $booking->project_id);
        $heldBy = $this->ctx->requireMapId('users', $booking->created_by);
        $createdAt = $this->t->legacyTs($booking->created_at) ?? $this->t->ts($booking->booking_date) ?? now()->format('Y-m-d H:i:s');
        $paymentDate = $this->paymentDates[(int) $booking->id] ?? null;

        // Per booked unit → reservation (§5.12; source key = booked_estate row id).
        foreach ($bookedRows as $bookedRow) {
            [$reservationId] = $this->sync('booked_estate', (int) $bookedRow->id, 'reservations', [
                'unit_id' => $this->ctx->requireMapId('estates', $bookedRow->estate_id),
                'client_project_id' => $projectId,
                'held_by' => $heldBy,
                'held_at' => $this->t->ts($booking->booking_date) ?? $createdAt,
                'expires_at' => null,
                'hold_status' => $accepted ? 'converted' : 'expired',
                'status' => 'active',
                'created_at' => $createdAt,
                'updated_at' => $this->t->legacyTs($booking->updated_at) ?? $createdAt,
            ]);
            $this->firstReservation[(int) $booking->id] ??= $reservationId;

            // Keep the app's shortlist→deal narrative coherent (§5.12).
            $this->sync('synth:shortlist_from_booked', (int) $bookedRow->id, 'shortlist_items', [
                'client_project_id' => $projectId,
                'office_visit_id' => $this->ctx->mapId('visit_reports', $bookedRow->visit_report_id),
                'shortlistable_type' => 'unit',
                'shortlistable_id' => $this->ctx->requireMapId('estates', $bookedRow->estate_id),
                'state' => $accepted ? 'won' : 'lost',
                'note' => null,
                'status' => 'active',
                'created_at' => $this->t->legacyTs($bookedRow->created_at) ?? $createdAt,
                'updated_at' => $this->t->legacyTs($bookedRow->updated_at) ?? $createdAt,
            ]);
        }

        if (! $accepted) {
            return;
        }

        $total = null;
        foreach ($bookedRows as $bookedRow) {
            $price = $this->t->priceDa($this->estatePrices[(int) $bookedRow->estate_id] ?? null);
            $total = $price !== null ? ($total ?? 0) + $price : $total;
        }

        [$dealId] = $this->sync('synth:deal_from_booking', (int) $booking->id, 'deals', [
            'client_project_id' => $projectId,
            'state' => 'won',
            'total_price' => $total,
            'notes' => "Réservation legacy #{$booking->id} du {$booking->booking_date}",
            'created_by' => $heldBy,
            'status' => 'active',
            'created_at' => $createdAt,
            'updated_at' => $this->t->legacyTs($booking->updated_at) ?? $createdAt,
        ]);

        if ($bookedRows === []) {
            $this->ctx->warn('booking_without_unit', "Booking #{$booking->id} has no booked unit — deal created without items; fix in app (§5.12).");
        }

        foreach ($bookedRows as $bookedRow) {
            $closedAt = $this->t->ts($paymentDate);
            if ($closedAt === null) {
                $closedAt = $this->t->ts($booking->booking_date) ?? $createdAt;
                $this->ctx->warn('deal_item_no_payment_date', "Booking #{$booking->id}: no payment date for the closed deal item — used the booking date.");
            }
            $this->sync('synth:deal_item_from_booked', (int) $bookedRow->id, 'deal_items', [
                'deal_id' => $dealId,
                'unit_id' => $this->ctx->requireMapId('estates', $bookedRow->estate_id),
                'state' => 'won',
                'agreed_price' => $this->t->priceDa($this->estatePrices[(int) $bookedRow->estate_id] ?? null),
                'closed_at' => $closedAt,
                'box_linked' => 0,
                'status' => 'active',
                'created_at' => $createdAt,
                'updated_at' => $this->t->legacyTs($booking->updated_at) ?? $createdAt,
            ]);
        }
    }

    /** §5.12 — extending_reports → activity_log on the booking's reservation. */
    private function syncExtendings(): void
    {
        foreach ($this->ctx->legacy->table('extending_reports')->orderBy('id')->get() as $row) {
            $reservationId = $this->firstReservation[(int) $row->booking_report_id] ?? null;
            if ($reservationId === null) {
                $this->ctx->warn('extending_orphan', "extending_report #{$row->id}: booking #{$row->booking_report_id} has no reservation — logged nowhere.");

                continue;
            }
            $this->sync('extending_reports', (int) $row->id, 'activity_log', [
                'user_id' => $this->ctx->mapId('users', $row->created_by),
                'action' => 'legacy.reservation_extended',
                'subject_type' => Reservation::class,
                'subject_id' => $reservationId,
                'changes' => json_encode([
                    'extending_date' => $row->extending_date,
                    'description' => $row->description,
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => $this->t->legacyTs($row->created_at) ?? $this->t->ts($row->extending_date) ?? now()->format('Y-m-d H:i:s'),
            ], []);
        }
    }
}
