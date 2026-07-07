<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\Importers;

use App\Modules\LegacyImport\Support\BaseImporter;

/**
 * §5.7 — legacy box_locals (empty today; future-proof). box → boxes with the
 * area folded into the reference (boxes have no notes column); local → units
 * with room type LOCO.
 */
class BoxLocalImporter extends BaseImporter
{
    public function phase(): string
    {
        return 'box_locals';
    }

    protected function sourceTable(): string
    {
        return 'box_locals';
    }

    protected function targetTable(): string
    {
        return 'boxes'; // per-row, see syncRow()
    }

    protected function map(object $row): ?array
    {
        return null; // unused — syncRow() branches per row
    }

    protected function syncRow(object $row): void
    {
        $isBox = $row->box_or_local !== 'local';
        $now = now()->format('Y-m-d H:i:s');
        $common = [
            'location_id' => $this->ctx->requireMapId('estate_categories', $row->name),
            'price' => $this->t->priceDa($row->price) ?? 0.00,
            'sale_status' => 'available',
            'status' => 'active',
            'created_at' => $this->t->legacyTs($row->created_at) ?? $now,
            'updated_at' => $this->t->legacyTs($row->updated_at) ?? $now,
            '_hash_only' => ['is_available' => (int) $row->is_available],
        ];

        if ($isBox) {
            $payload = $common + [
                'reference' => 'BOX-'.$row->number.($row->area ? " ({$row->area} m²)" : ''),
                'type_id' => $this->lists->resolve('box_types', 'Parking'),
            ];
            $this->sync('box_locals', (int) $row->id, 'boxes', $payload, $this->insertOnlyColumns());

            return;
        }

        $note = trim((string) $row->note);
        $payload = $common + [
            'reference' => $note !== '' ? $note : 'LOCAL-'.$row->number,
            'room_number_id' => $this->lists->resolve('room_numbers', 'LOCO'),
            'area_sqm' => $row->area,
            'gtm_priority' => 'medium',
        ];
        $this->sync('box_locals', (int) $row->id, 'units', $payload, ['status', 'sale_status', 'gtm_priority']);
    }

    protected function insertOnlyColumns(): array
    {
        return ['status', 'sale_status'];
    }
}
