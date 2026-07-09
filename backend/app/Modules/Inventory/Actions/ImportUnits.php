<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Enums\GtmPriority;
use App\Modules\Inventory\Enums\SaleStatus;
use App\Modules\Inventory\Events\UnitRepriced;
use App\Modules\Inventory\Events\UnitsImported;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Inventory\Support\UnitReference;
use App\Modules\Settings\Models\DynamicListItem;
use App\Modules\Settings\Models\User;
use Illuminate\Support\Collection;

/**
 * CSV round-trip for fast bulk editing: rows with an `id` update that unit
 * (spec fields in place; a price change goes through supersedeWith so the
 * correction stays versioned like /correct), rows without an `id` create a new
 * unit in the given project (blank reference → auto-generated, UnitReference).
 * sale_status is deliberately read-only — it is lifecycle-managed by holds and
 * deals, a spreadsheet must never flip a sold unit back to available.
 *
 * Valid rows apply, bad rows are skipped and reported with their line number.
 * No per-unit bells (see UnitsImported); created/repriced units still dispatch
 * UnitRepriced so the targeted desire-matching runs.
 */
class ImportUnits
{
    /** Spec columns importable as-is (the finish prices and reference are special-cased). */
    private const SPEC_COLUMNS = ['area_sqm', 'block', 'stack_floor', 'position', 'gtm_priority'];

    /** @var Collection<string, DynamicListItem>|null label/value (lowercased) → item */
    private ?Collection $rooms = null;

    private ?Collection $floors = null;

    /** @var array<int, array<string, true>> refs taken per location during THIS import */
    private array $takenRefs = [];

    /**
     * @return array{created: int, updated: int, errors: list<array{line: int, message: string}>}
     */
    public function handle(User $user, string $csv): array
    {
        [$header, $stream] = $this->open($csv);

        $created = 0;
        $updated = 0;
        $errors = [];
        $line = 1;

        while (($cells = fgetcsv($stream, 0, $header['delimiter'], '"', '\\')) !== false) {
            $line++;
            if ($cells === [null] || implode('', array_map(strval(...), $cells)) === '') {
                continue; // blank line
            }

            $row = $this->mapRow($header['columns'], $cells);

            try {
                if (($row['id'] ?? '') !== '') {
                    $updated += $this->updateRow($user, $row) ? 1 : 0;
                } else {
                    $this->createRow($row);
                    $created++;
                }
            } catch (\Throwable $e) {
                $errors[] = ['line' => $line, 'message' => $e->getMessage()];
            }
        }

        fclose($stream);

        if ($created + $updated > 0) {
            UnitsImported::dispatch($user, $created, $updated);
        }

        return ['created' => $created, 'updated' => $updated, 'errors' => $errors];
    }

    /**
     * @return array{0: array{delimiter: string, columns: list<string>}, 1: resource}
     */
    private function open(string $csv): array
    {
        // Strip the UTF-8 BOM our own export prepends (Excel needs it).
        if (str_starts_with($csv, "\xEF\xBB\xBF")) {
            $csv = substr($csv, 3);
        }

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $csv);
        rewind($stream);

        $firstLine = (string) fgets($stream);
        // French Excel saves with semicolons — accept both.
        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';

        $columns = array_map(
            fn ($c) => strtolower(trim((string) $c)),
            str_getcsv($firstLine, $delimiter, '"', '\\'),
        );

        abort_if(
            ! in_array('id', $columns, true) && ! in_array('location_id', $columns, true) && ! in_array('project', $columns, true),
            422,
            __('app.units_import_bad_header'),
        );

        return [['delimiter' => $delimiter, 'columns' => $columns], $stream];
    }

    /** @return array<string, string> column → trimmed cell (only columns present in the file) */
    private function mapRow(array $columns, array $cells): array
    {
        $row = [];
        foreach ($columns as $i => $column) {
            if ($column !== '') {
                $row[$column] = trim((string) ($cells[$i] ?? ''));
            }
        }

        return $row;
    }

    /** @return bool whether anything actually changed */
    private function updateRow(User $user, array $row): bool
    {
        $unit = Unit::query()->active()->find((int) $row['id']);
        throw_if($unit === null, new \RuntimeException(__('app.units_import_unknown_id', ['id' => $row['id']])));

        $specs = $this->specsFrom($row, $unit->location);

        if (array_key_exists('reference', $row) && $row['reference'] !== '') {
            $this->assertReferenceFree($unit->location, $row['reference'], $unit->id);
            $specs['reference'] = $row['reference'];
        }

        $unit->fill($specs);
        $changed = $unit->isDirty();
        $unit->save();

        // A price change is a correction: version it exactly like /correct does.
        // Both finish prices in ONE supersede so a row edit stays a single version.
        $corrections = [];
        foreach ($this->pricesFrom($row) as $column => $price) {
            if ((float) $price !== (float) $unit->{$column}) {
                $corrections[$column] = $price;
            }
        }

        if ($corrections !== []) {
            $unit = $unit->supersedeWith(
                $corrections,
                __('app.units_import_reason', ['user' => $user->name]),
            );
            UnitRepriced::dispatch($unit);
            $changed = true;
        }

        return $changed;
    }

    /**
     * The finish prices a row carries (blank cells are "leave untouched", never
     * "remove the offer" — removing a price goes through /correct deliberately).
     * `price` is the pre-dual-price export header: it always meant semi-fini.
     *
     * @return array<string, string> column → raw cell value
     */
    private function pricesFrom(array $row): array
    {
        $prices = [];

        $semiFini = $row['price_semi_fini'] ?? $row['price'] ?? '';
        if ($semiFini !== '') {
            $prices['price_semi_fini'] = $semiFini;
        }
        if (($row['price_fini'] ?? '') !== '') {
            $prices['price_fini'] = $row['price_fini'];
        }

        return $prices;
    }

    private function createRow(array $row): void
    {
        $location = $this->resolveLocation($row);
        $prices = $this->pricesFrom($row);
        throw_if($prices === [], new \RuntimeException(__('app.units_import_price_required')));

        $specs = $this->specsFrom($row, $location);

        $reference = $row['reference'] ?? '';
        if ($reference !== '') {
            $this->assertReferenceFree($location, $reference);
        } else {
            $reference = $this->generateReference($location, $specs);
        }
        $this->takenRefs[$location->id][mb_strtolower($reference)] = true;

        $unit = $location->units()->create($specs + $prices + [
            'reference' => $reference,
            'sale_status' => SaleStatus::Available->value,
            'gtm_priority' => $specs['gtm_priority'] ?? GtmPriority::Medium->value,
        ]);

        // Targeted desire-matching only — no team-wide "new unit" bell per row.
        UnitRepriced::dispatch($unit);
    }

    /** @return array<string, mixed> validated spec attributes present in the row */
    private function specsFrom(array $row, Location $location): array
    {
        $specs = [];

        if (array_key_exists('rooms', $row)) {
            $specs['room_number_id'] = $this->resolveListItem('room_numbers', $row['rooms']);
        }
        if (array_key_exists('floor', $row)) {
            $specs['floor_id'] = $this->resolveListItem('floors', $row['floor']);
        }

        foreach (self::SPEC_COLUMNS as $column) {
            if (! array_key_exists($column, $row)) {
                continue;
            }
            $value = $row[$column] === '' ? null : $row[$column];

            $specs[$column] = match ($column) {
                'area_sqm' => $value === null ? null : (float) $value,
                'stack_floor', 'position' => $value === null ? null : (int) $value,
                'gtm_priority' => $this->resolvePriority($value),
                default => $value,
            };
        }

        return array_filter(
            $specs,
            // gtm_priority keeps its DB default on create when the cell is blank.
            fn ($v, $k) => ! ($k === 'gtm_priority' && $v === null),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    private function resolveLocation(array $row): Location
    {
        if (($row['location_id'] ?? '') !== '') {
            $location = Location::query()->find((int) $row['location_id']);
            throw_if($location === null, new \RuntimeException(__('app.units_import_unknown_project', ['project' => $row['location_id']])));

            return $location;
        }

        $name = trim($row['project'] ?? '');
        throw_if($name === '', new \RuntimeException(__('app.units_import_project_required')));

        $location = Location::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();
        throw_if($location === null, new \RuntimeException(__('app.units_import_unknown_project', ['project' => $name])));

        return $location;
    }

    /** Resolve a rooms/floor cell against the dynamic list (label, translation or value). */
    private function resolveListItem(string $listKey, string $cell): ?int
    {
        if ($cell === '') {
            return null;
        }

        $items = $this->{$listKey === 'room_numbers' ? 'rooms' : 'floors'} ??= $this->listLookup($listKey);
        $item = $items->get(mb_strtolower($cell));

        throw_if($item === null, new \RuntimeException(__('app.units_import_unknown_item', ['value' => $cell])));

        return $item->id;
    }

    /** @return Collection<string, DynamicListItem> */
    private function listLookup(string $listKey): Collection
    {
        $lookup = collect();

        DynamicListItem::query()
            ->whereHas('list', fn ($q) => $q->where('key', $listKey))
            ->get()
            ->each(function (DynamicListItem $item) use ($lookup) {
                $keys = array_merge(
                    [$item->label, $item->value],
                    array_values($item->label_translations ?? []),
                );
                foreach ($keys as $key) {
                    if ($key !== null && trim((string) $key) !== '') {
                        $lookup->put(mb_strtolower(trim((string) $key)), $item);
                    }
                }
            });

        return $lookup;
    }

    private function resolvePriority(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $priority = GtmPriority::tryFrom(strtolower($value));
        throw_if($priority === null, new \RuntimeException(__('app.units_import_unknown_item', ['value' => $value])));

        return $priority->value;
    }

    private function assertReferenceFree(Location $location, string $reference, ?int $exceptId = null): void
    {
        $clashes = isset($this->takenRefs[$location->id][mb_strtolower($reference)])
            || Unit::query()->active()
                ->where('location_id', $location->id)
                ->whereRaw('LOWER(reference) = ?', [mb_strtolower($reference)])
                ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
                ->exists();

        throw_if($clashes, new \RuntimeException(__('app.units_import_duplicate_reference', ['reference' => $reference])));
    }

    private function generateReference(Location $location, array $specs): string
    {
        $roomsLabel = isset($specs['room_number_id']) ? DynamicListItem::find($specs['room_number_id'])?->label : null;
        $floorLabel = isset($specs['floor_id']) ? DynamicListItem::find($specs['floor_id'])?->label : null;

        $base = UnitReference::build(
            $location,
            $roomsLabel,
            $floorLabel,
            $specs['block'] ?? null,
            $specs['stack_floor'] ?? null,
            $specs['position'] ?? null,
        );

        $taken = Unit::query()->active()
            ->where('location_id', $location->id)
            ->pluck('reference')
            ->concat(array_keys($this->takenRefs[$location->id] ?? []));

        return UnitReference::dedupe($base, $taken);
    }
}
