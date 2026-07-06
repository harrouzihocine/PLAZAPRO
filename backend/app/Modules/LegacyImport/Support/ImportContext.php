<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Shared state of one legacy:import run: both connections, the legacy_map
 * cache, per-phase counters, the warning ledger and the dry-run switch
 * (PLAZA_MIGRATION_PLAN.md §2.2). All target writes go through this class so
 * dry-run can transform everything, allocate fake ids for FK translation and
 * still write nothing.
 */
class ImportContext
{
    public readonly Connection $target;

    public readonly Connection $legacy;

    /** @var array<string, mixed> config('legacy_import') snapshot */
    public readonly array $config;

    public bool $dryRun = false;

    public int $chunkSize = 500;

    public ?int $systemUserId = null;

    /** @var array<string, array<int, array{target_table: string, target_id: int, row_hash: string, adopted: bool}>> */
    private array $map = [];

    /** @var array<string, bool> source tables whose map is fully preloaded */
    private array $preloaded = [];

    /** @var array<string, array<string, int>> phase → counter → n */
    private array $counters = [];

    /** @var list<array{category: string, message: string, context: array}> */
    private array $warnings = [];

    /** Fake-id sequence used for dry-run inserts (negative, never persisted). */
    private int $dryRunId = -1;

    public function __construct()
    {
        $this->config = config('legacy_import');
        $this->target = DB::connection();
        $this->legacy = DB::connection($this->config['connection']);
    }

    public function cfg(string $key, mixed $default = null): mixed
    {
        return data_get($this->config, $key, $default);
    }

    /* ------------------------------------------------------------------ */
    /* legacy_map ledger */
    /* ------------------------------------------------------------------ */

    public function preloadMap(string $sourceTable): void
    {
        if (isset($this->preloaded[$sourceTable])) {
            return;
        }
        $rows = $this->target->table('legacy_map')
            ->where('source_table', $sourceTable)
            ->get(['source_id', 'target_table', 'target_id', 'row_hash', 'adopted']);
        foreach ($rows as $row) {
            $this->map[$sourceTable][(int) $row->source_id] = [
                'target_table' => $row->target_table,
                'target_id' => (int) $row->target_id,
                'row_hash' => $row->row_hash,
                'adopted' => (bool) $row->adopted,
            ];
        }
        $this->preloaded[$sourceTable] = true;
    }

    /** @return array{target_table: string, target_id: int, row_hash: string, adopted: bool}|null */
    public function getMap(string $sourceTable, int $sourceId): ?array
    {
        $this->preloadMap($sourceTable);

        return $this->map[$sourceTable][$sourceId] ?? null;
    }

    public function mapId(string $sourceTable, int|string|null $sourceId): ?int
    {
        if ($sourceId === null || (int) $sourceId === 0) {
            return null;
        }

        return $this->getMap($sourceTable, (int) $sourceId)['target_id'] ?? null;
    }

    /**
     * Translate a legacy FK that MUST already be mapped — a miss is an
     * ordered-run bug (§2.2), not a data condition: abort loudly.
     */
    public function requireMapId(string $sourceTable, int|string $sourceId): int
    {
        $id = $this->mapId($sourceTable, $sourceId);
        if ($id === null) {
            throw new RuntimeException(
                "legacy:import ordering bug — no legacy_map entry for {$sourceTable}#{$sourceId}. "
                .'The phase that imports that table must run first.'
            );
        }

        return $id;
    }

    public function putMap(
        string $sourceTable,
        int $sourceId,
        string $targetTable,
        int $targetId,
        string $rowHash,
        bool $adopted = false,
    ): void {
        $now = now()->format('Y-m-d H:i:s');
        if (! $this->dryRun) {
            $this->target->table('legacy_map')->updateOrInsert(
                ['source_table' => $sourceTable, 'source_id' => $sourceId],
                [
                    'target_table' => $targetTable,
                    'target_id' => $targetId,
                    'row_hash' => $rowHash,
                    'adopted' => $adopted,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
        $this->map[$sourceTable][$sourceId] = [
            'target_table' => $targetTable,
            'target_id' => $targetId,
            'row_hash' => $rowHash,
            'adopted' => $adopted,
        ];
    }

    public function updateMapHash(string $sourceTable, int $sourceId, string $rowHash): void
    {
        if (! $this->dryRun) {
            $this->target->table('legacy_map')
                ->where('source_table', $sourceTable)
                ->where('source_id', $sourceId)
                ->update(['row_hash' => $rowHash, 'updated_at' => now()->format('Y-m-d H:i:s')]);
        }
        if (isset($this->map[$sourceTable][$sourceId])) {
            $this->map[$sourceTable][$sourceId]['row_hash'] = $rowHash;
        }
    }

    /* ------------------------------------------------------------------ */
    /* Writes (dry-run aware) */
    /* ------------------------------------------------------------------ */

    /** Insert into a target table; in dry-run allocates a fake negative id. */
    public function insert(string $table, array $columns): int
    {
        if ($this->dryRun) {
            return $this->dryRunId--;
        }

        return (int) $this->target->table($table)->insertGetId($columns);
    }

    public function update(string $table, int $id, array $columns): void
    {
        if ($this->dryRun || $columns === [] || $id < 0) {
            return;
        }
        $this->target->table($table)->where('id', $id)->update($columns);
    }

    /* ------------------------------------------------------------------ */
    /* Counters & warnings (§7) */
    /* ------------------------------------------------------------------ */

    public function count(string $phase, string $counter, int $by = 1): void
    {
        $this->counters[$phase][$counter] = ($this->counters[$phase][$counter] ?? 0) + $by;
    }

    /** @return array<string, array<string, int>> */
    public function counters(): array
    {
        return $this->counters;
    }

    public function warn(string $category, string $message, array $context = []): void
    {
        $this->warnings[] = ['category' => $category, 'message' => $message, 'context' => $context];
    }

    /** @return list<array{category: string, message: string, context: array}> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    public function requireSystemUserId(): int
    {
        if ($this->systemUserId === null) {
            throw new RuntimeException('legacy:import ordering bug — system_user phase has not run.');
        }

        return $this->systemUserId;
    }
}
