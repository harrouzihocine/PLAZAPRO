<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\Importers;

use App\Modules\LegacyImport\Support\BaseImporter;
use App\Modules\Payments\Models\Versement;

/**
 * §5.14 — the 23 payment_reports → versements. Legacy v1 never stored
 * amounts: every row imports at 0.00 with a LEGACY reference marker and goes
 * to the ledger for manual backfill in-app (amount/reference are insert-only
 * so the backfill survives re-syncs). Booking-linked payments take the
 * booking's single unit; the 6 direct payments (2025–26) carry their own
 * estate and additionally synthesize a converted reservation + won deal/item
 * (§5.14). Descriptions land in activity_log (FQCN morph).
 */
class VersementImporter extends BaseImporter
{
    /** @var array<int, list<object>> booking id → booked_estate rows */
    private array $bookedByBooking = [];

    /** @var array<int, float|string|null> legacy estate id → raw legacy price */
    private array $estatePrices = [];

    public function phase(): string
    {
        return 'versements';
    }

    protected function sourceTable(): string
    {
        return 'payment_reports';
    }

    protected function targetTable(): string
    {
        return 'versements';
    }

    protected function beforeRun(): void
    {
        foreach (['projects', 'users', 'estates', 'synth:payment_note', 'synth:reservation_from_payment', 'synth:deal_from_payment', 'synth:deal_item_from_payment'] as $table) {
            $this->ctx->preloadMap($table);
        }
        $this->bookedByBooking = $this->ctx->legacy->table('booked_estate')
            ->whereNotNull('booking_report_id')->orderBy('id')->get()
            ->groupBy('booking_report_id')->map(fn ($rows) => $rows->all())->all();
        $this->estatePrices = $this->ctx->legacy->table('estates')->pluck('price', 'id')->all();
    }

    protected function map(object $row): ?array
    {
        return [
            'client_project_id' => $this->ctx->requireMapId('projects', $row->project_id),
            'unit_id' => $this->unitFor($row),
            'amount' => 0.00,
            'paid_on' => substr((string) $row->payment_date, 0, 10),
            'method_id' => $this->lists->resolve('payment_methods', $row->method),
            'reference' => "LEGACY {$row->method} — montant à saisir",
            'recorded_by' => $this->ctx->requireMapId('users', $row->created_by),
            'status' => 'active',
            'created_at' => $this->t->legacyTs($row->created_at) ?? $this->t->ts($row->payment_date) ?? now()->format('Y-m-d H:i:s'),
            'updated_at' => $this->t->legacyTs($row->updated_at) ?? $this->t->ts($row->payment_date) ?? now()->format('Y-m-d H:i:s'),
        ];
    }

    protected function insertOnlyColumns(): array
    {
        // amount/reference are placeholders Hocine backfills in-app — a
        // re-sync must never reset them (§5.14).
        return ['status', 'amount', 'reference'];
    }

    protected function afterSync(object $row, int $targetId, string $mode, array $payload): void
    {
        // §5.14 — the free-text description → activity_log on the versement.
        if (trim((string) $row->description) !== '') {
            $this->sync('synth:payment_note', (int) $row->id, 'activity_log', [
                'user_id' => $payload['recorded_by'],
                'action' => 'legacy.payment_note',
                'subject_type' => Versement::class,
                'subject_id' => $targetId,
                'changes' => json_encode(['description' => $row->description], JSON_UNESCAPED_UNICODE),
                'created_at' => $payload['created_at'],
            ], []);
        }

        // Direct payments (no booking, real estate_id) synthesize the missing
        // reservation + won deal so the §6 derivation sees a normal sale.
        if ($row->booking_report_id !== null || (int) $row->estate_id === 0) {
            return;
        }

        $unitId = $this->ctx->mapId('estates', $row->estate_id);
        $paidTs = $this->t->ts($row->payment_date) ?? $payload['created_at'];
        $agreed = $this->t->priceDa($this->estatePrices[(int) $row->estate_id] ?? null);

        // Estate deleted from legacy (payment #28 → estate 653): no unit to
        // hold/close — deal created without items, like §5.12's unit-less
        // bookings.
        if ($unitId === null) {
            $this->ctx->warn('payment_estate_deleted', "Payment #{$row->id}: estate #{$row->estate_id} was deleted from legacy — won deal created without a unit; fix in app.");
        }

        if ($unitId !== null) {
            $this->sync('synth:reservation_from_payment', (int) $row->id, 'reservations', [
                'unit_id' => $unitId,
                'client_project_id' => $payload['client_project_id'],
                'held_by' => $payload['recorded_by'],
                'held_at' => $paidTs,
                'expires_at' => null,
                'hold_status' => 'converted',
                'status' => 'active',
                'created_at' => $payload['created_at'],
                'updated_at' => $payload['updated_at'],
            ]);
        }

        [$dealId] = $this->sync('synth:deal_from_payment', (int) $row->id, 'deals', [
            'client_project_id' => $payload['client_project_id'],
            'state' => 'won',
            'total_price' => $agreed,
            'notes' => "Paiement direct legacy #{$row->id} du ".substr((string) $row->payment_date, 0, 10),
            'created_by' => $payload['recorded_by'],
            'status' => 'active',
            'created_at' => $payload['created_at'],
            'updated_at' => $payload['updated_at'],
        ]);

        if ($unitId !== null) {
            $this->sync('synth:deal_item_from_payment', (int) $row->id, 'deal_items', [
                'deal_id' => $dealId,
                'unit_id' => $unitId,
                'state' => 'won',
                'agreed_price' => $agreed,
                'closed_at' => $paidTs,
                'box_linked' => 0,
                'status' => 'active',
                'created_at' => $payload['created_at'],
                'updated_at' => $payload['updated_at'],
            ]);
        }
    }

    /** §5.14 — booking-linked: the booking's single unit; direct: its estate. */
    private function unitFor(object $row): ?int
    {
        if ($row->booking_report_id === null) {
            // NULL when the estate was deleted from legacy (payment #28).
            return $this->ctx->mapId('estates', $row->estate_id);
        }
        $booked = $this->bookedByBooking[(int) $row->booking_report_id] ?? [];
        if (count($booked) === 1) {
            return $this->ctx->requireMapId('estates', $booked[0]->estate_id);
        }
        $this->ctx->warn('versement_unit_unknown', "Payment #{$row->id}: booking #{$row->booking_report_id} has ".count($booked).' booked units — versement imported without a unit; set it in-app.');

        return null;
    }
}
