<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\Importers;

use App\Modules\LegacyImport\Support\BaseImporter;

/**
 * §4.4 — wilayas are matched by code (legacy tinyint vs new varchar, compared
 * as int), NEVER created. A miss goes to the warning ledger.
 */
class WilayaMatcher extends BaseImporter
{
    public function phase(): string
    {
        return 'wilayas';
    }

    protected function sourceTable(): string
    {
        return 'wilayas';
    }

    protected function targetTable(): string
    {
        return 'wilayas';
    }

    protected function map(object $row): ?array
    {
        return null; // unused — custom run()
    }

    public function run(): void
    {
        $this->ctx->preloadMap('wilayas');

        $byCode = [];
        foreach ($this->ctx->target->table('wilayas')->get(['id', 'code']) as $wilaya) {
            $byCode[(int) $wilaya->code] = (int) $wilaya->id;
        }

        foreach ($this->ctx->legacy->table('wilayas')->orderBy('id')->get() as $row) {
            $targetId = $byCode[(int) $row->code] ?? null;
            if ($targetId === null) {
                $this->ctx->warn('wilaya_unmatched', "Legacy wilaya #{$row->id} code {$row->code} '{$row->name}' has no match.");
                $this->ctx->count($this->phase(), 'ignored');

                continue;
            }
            $existing = $this->ctx->getMap('wilayas', (int) $row->id);
            if ($existing !== null && $existing['target_id'] === $targetId) {
                $this->ctx->count($this->phase(), 'skipped');

                continue;
            }
            $this->ctx->putMap('wilayas', (int) $row->id, 'wilayas', $targetId, md5((string) $row->code), adopted: true);
            $this->ctx->count($this->phase(), 'adopted');
        }
    }
}
