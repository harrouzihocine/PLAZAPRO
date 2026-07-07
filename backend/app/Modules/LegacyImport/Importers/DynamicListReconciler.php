<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\Importers;

use App\Modules\LegacyImport\Support\BaseImporter;

/**
 * §4.3 — walks every legacy lookup table through the DynamicListResolver so
 * that all pins are verified and all missing items are created up-front and
 * deterministically, and records legacy-lookup-id → dynamic_list_items id in
 * legacy_map for the FK translations of later phases.
 *
 * estate_delivery_methods is deliberately absent: delivery has no new home —
 * the labels are read directly by the locations/desires importers and written
 * into description/notes text (§4.3).
 */
class DynamicListReconciler extends BaseImporter
{
    /** legacy table → [label column, list key] */
    private const TABLES = [
        'sources' => ['name', 'sources'],
        'ratings' => ['name', 'client_ratings'],
        'estate_floors' => ['floor', 'floors'],
        'estate_rooms_numbers' => ['number', 'room_numbers'],
        'estate_residence_types' => ['type', 'project_types'],
        'estate_contract_types' => ['type', 'contract_types'],
        'estate_payment_methods' => ['method', 'project_payment_methods'],
    ];

    public function phase(): string
    {
        return 'lists';
    }

    protected function sourceTable(): string
    {
        return 'dynamic_lists';
    }

    protected function targetTable(): string
    {
        return 'dynamic_list_items';
    }

    protected function map(object $row): ?array
    {
        return null; // unused — custom run()
    }

    public function run(): void
    {
        foreach (self::TABLES as $table => [$column, $listKey]) {
            $this->reconcileTable($table, $column, $listKey);
        }

        // reasons → archive_reasons; desire-typed rows are tagged in meta (§4.3).
        $this->ctx->preloadMap('reasons');
        foreach ($this->ctx->legacy->table('reasons')->orderBy('id')->get() as $row) {
            $meta = $row->type === 'desire' ? ['legacy_type' => 'desire'] : [];
            $this->link('reasons', $row, (string) $row->name, 'archive_reasons', $meta);
        }

        // payment_reports.method is an inline enum, not a lookup table: resolve
        // the distinct labels now so `arboun` / `legacy_unspecified` exist (§4.3).
        $methods = $this->ctx->legacy->table('payment_reports')->distinct()->pluck('method');
        foreach ($methods as $method) {
            $this->lists->resolve('payment_methods', (string) $method);
        }
    }

    private function reconcileTable(string $table, string $column, string $listKey): void
    {
        $this->ctx->preloadMap($table);
        foreach ($this->ctx->legacy->table($table)->orderBy('id')->get() as $row) {
            $this->link($table, $row, (string) $row->{$column}, $listKey);
        }
    }

    private function link(string $table, object $row, string $label, string $listKey, array $meta = []): void
    {
        $itemId = $this->lists->resolve($listKey, $label, $meta);
        if ($itemId === null) {
            $this->ctx->warn('list_label_empty', "Legacy {$table}#{$row->id} has an empty label — not mapped.");
            $this->ctx->count($this->phase(), 'ignored');

            return;
        }
        $hash = md5($label);
        $existing = $this->ctx->getMap($table, (int) $row->id);
        if ($existing !== null && $existing['target_id'] === $itemId && $existing['row_hash'] === $hash) {
            $this->ctx->count($this->phase(), 'skipped');

            return;
        }
        $this->ctx->putMap($table, (int) $row->id, 'dynamic_list_items', $itemId, $hash, adopted: true);
        $this->ctx->count($this->phase(), $existing === null ? 'adopted' : 'updated');
    }
}
