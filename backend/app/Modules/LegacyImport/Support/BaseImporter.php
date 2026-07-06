<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

/**
 * Chunked hash-diff sync engine (PLAZA_MIGRATION_PLAN.md §2.2). Per source
 * row: build the transformed payload, md5 it, then insert / update / skip via
 * the legacy_map ledger. Never deletes, never touches unmapped columns, and
 * only updates when the SOURCE changed (hash diff). Rows whose target
 * pre-existed (matchExisting) are "adopted": they only ever get NULL columns
 * filled, never overwritten (§5.5 one-way enrich).
 *
 * All writes go through ImportContext, so --dry-run transforms everything,
 * counts and warns, and writes nothing.
 */
abstract class BaseImporter
{
    public function __construct(
        protected readonly ImportContext $ctx,
        protected readonly DynamicListResolver $lists,
        protected readonly Transform $t,
    ) {}

    /** Phase key as used by --only= and the counters. */
    abstract public function phase(): string;

    /** legacy_map.source_table for the primary rows of this phase. */
    abstract protected function sourceTable(): string;

    abstract protected function targetTable(): string;

    /**
     * Legacy row → target payload. `_hash_only` entries participate in the
     * row hash but are stripped before writing (e.g. estates.is_available so
     * legacy toggles trigger re-derivation, §5.6). NULL = ignore the row.
     */
    abstract protected function map(object $row): ?array;

    /**
     * Columns written on insert but never on re-run updates: app-owned state
     * (§2.5 — sale_status, stage, …) and placeholders Hocine backfills
     * in-app (versements.amount). They still count into the hash.
     *
     * @return list<string>
     */
    protected function insertOnlyColumns(): array
    {
        return ['status'];
    }

    /** Return an existing target id to adopt instead of inserting (§5.5/§5.6). */
    protected function matchExisting(object $row, array $payload): ?int
    {
        return null;
    }

    protected function sourceQuery(): Builder
    {
        return $this->ctx->legacy->table($this->sourceTable());
    }

    /** Hook for pivots / fan-out rows. $mode: inserted|adopted|updated|skipped. */
    protected function afterSync(object $row, int $targetId, string $mode, array $payload): void {}

    protected function beforeRun(): void {}

    protected function afterRun(): void {}

    public function run(): void
    {
        $this->ctx->preloadMap($this->sourceTable());
        $this->beforeRun();
        $this->sourceQuery()->chunkById(
            $this->ctx->chunkSize,
            fn (Collection $rows) => $this->ctx->target->transaction(function () use ($rows): void {
                foreach ($rows as $row) {
                    $this->syncRow($row);
                }
            })
        );
        $this->afterRun();
    }

    protected function syncRow(object $row): void
    {
        $payload = $this->map($row);
        if ($payload === null) {
            $this->ctx->count($this->phase(), 'ignored');

            return;
        }

        [$targetId, $mode] = $this->sync(
            $this->sourceTable(),
            (int) $row->id,
            $this->targetTable(),
            $payload,
            $this->insertOnlyColumns(),
            fn (array $p) => $this->matchExisting($row, $p),
        );

        $this->afterSync($row, $targetId, $mode, $payload);
    }

    /**
     * The §2.2 sync algorithm for one (source, payload) pair. Also used for
     * synthesized rows via virtual source tables (synth:*). Returns the
     * target id and what happened.
     *
     * @return array{0: int, 1: string}
     */
    protected function sync(
        string $sourceTable,
        int $sourceId,
        string $targetTable,
        array $payload,
        array $insertOnly = ['status'],
        ?callable $matchExisting = null,
    ): array {
        $hashOnly = $payload['_hash_only'] ?? [];
        unset($payload['_hash_only']);
        $hash = md5(json_encode([$payload, $hashOnly]));

        $map = $this->ctx->getMap($sourceTable, $sourceId);

        if ($map === null) {
            $existingId = $matchExisting !== null ? $matchExisting($payload) : null;
            if ($existingId !== null) {
                $this->fillNullColumns($targetTable, $existingId, $payload);
                $this->ctx->putMap($sourceTable, $sourceId, $targetTable, $existingId, $hash, adopted: true);
                $this->ctx->count($this->phase(), 'adopted');

                return [$existingId, 'adopted'];
            }

            $id = $this->ctx->insert($targetTable, $payload);
            $this->ctx->putMap($sourceTable, $sourceId, $targetTable, $id, $hash);
            $this->ctx->count($this->phase(), 'inserted');

            return [$id, 'inserted'];
        }

        if ($map['row_hash'] !== $hash) {
            if ($map['adopted']) {
                // One-way enrich: never overwrite a row the importer didn't create.
                $this->fillNullColumns($targetTable, $map['target_id'], $payload);
            } else {
                $columns = array_diff_key($payload, array_flip($insertOnly));
                $this->ctx->update($targetTable, $map['target_id'], $columns);
            }
            $this->ctx->updateMapHash($sourceTable, $sourceId, $hash);
            $this->ctx->count($this->phase(), 'updated');

            return [$map['target_id'], 'updated'];
        }

        $this->ctx->count($this->phase(), 'skipped');

        return [$map['target_id'], 'skipped'];
    }

    /** Adopted rows: fill NULL columns from the payload, touch nothing else. */
    private function fillNullColumns(string $targetTable, int $targetId, array $payload): void
    {
        if ($this->ctx->dryRun || $targetId < 0) {
            return;
        }
        $current = $this->ctx->target->table($targetTable)->where('id', $targetId)->first();
        if ($current === null) {
            return;
        }
        $fill = [];
        foreach ($payload as $column => $value) {
            if (in_array($column, ['id', 'created_at', 'updated_at', 'status'], true)) {
                continue;
            }
            if ($value !== null && property_exists($current, $column) && $current->{$column} === null) {
                $fill[$column] = $value;
            }
        }
        if ($fill !== []) {
            $this->ctx->update($targetTable, $targetId, $fill);
        }
    }
}
