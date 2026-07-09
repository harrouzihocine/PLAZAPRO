<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\Importers;

use App\Modules\LegacyImport\Support\BaseImporter;

/**
 * §5.6 — legacy estates → units. Identity comes from estates.note
 * (estates.name is the FK to estate_categories!). Converges onto the
 * hand-entered PERLA units via config unit_reference_pins (the plan's
 * reference/floor key cannot match the real data), falling back to the plan's
 * (location, lower(reference), floor) rule. sale_status is written 'available'
 * on insert only — the §6 derivation pass owns it afterwards; legacy
 * is_available participates in the hash so toggles trigger re-derivation.
 */
class UnitImporter extends BaseImporter
{
    /** @var array<int, string> */
    private array $floors = [];

    /** @var array<int, string> */
    private array $rooms = [];

    /** @var array<int, int> legacy estate id → 1-based position within (location, floor) */
    private array $positions = [];

    /** @var array<int, true> target unit ids already claimed by a map entry */
    private array $claimed = [];

    public function phase(): string
    {
        return 'units';
    }

    protected function sourceTable(): string
    {
        return 'estates';
    }

    protected function targetTable(): string
    {
        return 'units';
    }

    protected function beforeRun(): void
    {
        $this->floors = $this->ctx->legacy->table('estate_floors')->pluck('floor', 'id')->all();
        $this->rooms = $this->ctx->legacy->table('estate_rooms_numbers')->pluck('number', 'id')->all();

        // §5.6 position: 1-based sequence within (location, floor) by legacy id.
        $counters = [];
        foreach ($this->ctx->legacy->table('estates')->orderBy('id')->get(['id', 'name', 'floor']) as $estate) {
            $key = $estate->name.'/'.($estate->floor ?? 'null');
            $counters[$key] = ($counters[$key] ?? 0) + 1;
            $this->positions[(int) $estate->id] = $counters[$key];
        }

        $this->ctx->preloadMap('estates');
        foreach ($this->ctx->legacy->table('estates')->pluck('id') as $id) {
            $map = $this->ctx->getMap('estates', (int) $id);
            if ($map !== null) {
                $this->claimed[$map['target_id']] = true;
            }
        }
    }

    protected function map(object $row): ?array
    {
        $floorLabel = $this->floors[$row->floor] ?? null;
        $roomLabel = $this->rooms[$row->rooms_number] ?? null;
        $note = trim((string) $row->note);

        $price = $this->t->priceDa($row->price);
        if ($price === null) {
            $price = 0.00;
            $this->ctx->warn('unit_price_missing', "Legacy estate #{$row->id} has no price — imported at 0.00.");
        }

        return [
            'location_id' => $this->ctx->requireMapId('estate_categories', $row->name),
            'reference' => $note !== '' ? $note : ($roomLabel ?? 'UNIT').'-'.$row->id,
            'room_number_id' => $this->lists->resolve('room_numbers', $roomLabel),
            'floor_id' => $this->lists->resolve('floors', $floorLabel),
            'area_sqm' => $row->area,
            'price_semi_fini' => $price,
            'sale_status' => 'available',
            'block' => $this->block($note),
            'stack_floor' => $this->stackFloor($floorLabel),
            'position' => $this->positions[(int) $row->id] ?? null,
            'gtm_priority' => 'medium',
            'status' => 'active',
            'created_at' => $this->t->legacyTs($row->created_at),
            'updated_at' => $this->t->legacyTs($row->updated_at),
            '_hash_only' => ['is_available' => (int) $row->is_available],
        ];
    }

    protected function insertOnlyColumns(): array
    {
        return ['status', 'sale_status', 'gtm_priority'];
    }

    protected function afterSync(object $row, int $targetId, string $mode, array $payload): void
    {
        // Rows created/claimed this run are off-limits for adoption: legacy
        // duplicate references (F2/A on several floors, §5.6) must not fold
        // onto each other — only genuinely pre-existing units are adopted.
        $this->claimed[$targetId] = true;
    }

    protected function matchExisting(object $row, array $payload): ?int
    {
        // Deterministic convergence pin (legacy estate id → hand-entered reference).
        $pinnedReference = $this->ctx->cfg('unit_reference_pins', [])[(int) $row->id] ?? null;

        $query = $this->ctx->target->table('units')->where('location_id', $payload['location_id']);
        if ($pinnedReference !== null) {
            $query->where('reference', $pinnedReference);
        } else {
            // §5.6 fallback rule: (location, lower(reference), floor).
            $query->whereRaw('LOWER(reference) = ?', [mb_strtolower($payload['reference'])])
                ->where('floor_id', $payload['floor_id']);
        }

        foreach ($query->get(['id']) as $unit) {
            if (! isset($this->claimed[(int) $unit->id])) {
                $this->claimed[(int) $unit->id] = true;

                return (int) $unit->id;
            }
        }

        return null;
    }

    private function stackFloor(?string $floorLabel): ?int
    {
        return match (true) {
            $floorLabel === null => null,
            $floorLabel === 'RDC' => 0,
            $floorLabel === 'En s1' => -1,
            $floorLabel === 'En s2' => -2,
            is_numeric($floorLabel) => (int) $floorLabel,
            default => null,
        };
    }

    /** §5.6 — substring after '/' when the note matches X/Y, uppercased. */
    private function block(string $note): ?string
    {
        if (preg_match('#^([^/\s]+)/([^/\s]+)$#u', $note, $m) === 1) {
            return mb_strtoupper($m[2]);
        }

        return null;
    }
}
