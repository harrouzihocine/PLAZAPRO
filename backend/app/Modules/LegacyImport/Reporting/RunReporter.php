<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\Reporting;

use App\Modules\LegacyImport\Support\ImportContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * §7 — persists one legacy_import_runs row per real sync and writes the
 * human-readable run report (counters, verification checklist, warnings
 * ledger) plus duplicate-clients.csv under storage/app/legacy-import/.
 */
class RunReporter
{
    private ?int $runId = null;

    private string $startedAt;

    public function __construct(private readonly ImportContext $ctx) {}

    public function start(?string $dumpFile): void
    {
        $this->startedAt = now()->format('Y-m-d H:i:s');
        if ($this->ctx->dryRun) {
            return;
        }
        $this->runId = (int) $this->ctx->target->table('legacy_import_runs')->insertGetId([
            'started_at' => $this->startedAt,
            'dump_file' => $dumpFile,
            'dry_run' => 0,
            'created_at' => $this->startedAt,
            'updated_at' => $this->startedAt,
        ]);
    }

    /**
     * @param  array{checks: list<array{name: string, expected: string, actual: string, pass: bool}>, info: list<string>, passed: bool}|null  $verification
     * @return string the report path (storage/app relative)
     */
    public function finish(?array $verification, ?string $dumpFile): string
    {
        $finishedAt = now()->format('Y-m-d H:i:s');
        $warnings = collect($this->ctx->warnings())->groupBy('category');

        if ($this->runId !== null) {
            $this->ctx->target->table('legacy_import_runs')->where('id', $this->runId)->update([
                'finished_at' => $finishedAt,
                'stats' => json_encode($this->ctx->counters()),
                'warnings' => json_encode(
                    $warnings->map(fn ($group) => $group->pluck('message')->values())->all(),
                    JSON_UNESCAPED_UNICODE
                ),
                'updated_at' => $finishedAt,
            ]);
        }

        $dir = (string) $this->ctx->cfg('report_dir');
        $label = $this->runId !== null ? (string) $this->runId : 'dry-'.now()->format('Ymd-His');

        $duplicatesCsv = null;
        if (! $this->ctx->dryRun) {
            $duplicatesCsv = $this->writeDuplicateClientsCsv($dir);
        }

        $report = $this->render($label, $dumpFile, $finishedAt, $verification, $warnings, $duplicatesCsv);
        $path = "{$dir}/report-{$label}.md";
        Storage::disk('local')->put($path, $report);

        return $path;
    }

    /** §5.2 — duplicate-phone clients CSV for the in-app resolution workflow. */
    private function writeDuplicateClientsCsv(string $dir): ?string
    {
        $rows = $this->ctx->target->table('clients')
            ->join('legacy_map', fn ($join) => $join
                ->on('legacy_map.target_id', '=', 'clients.id')
                ->where('legacy_map.target_table', 'clients'))
            ->where('legacy_map.source_table', 'clients')
            ->whereNotNull('clients.phone_nsn')
            ->get(['clients.id', 'clients.first_name', 'clients.last_name', 'clients.phone_nsn'])
            ->groupBy('phone_nsn')
            ->filter(fn ($group) => $group->count() > 1);

        if ($rows->isEmpty()) {
            return null;
        }

        $csv = "phone_nsn,count,client_ids,names\n";
        foreach ($rows as $nsn => $group) {
            $names = $group->map(fn ($c) => trim("{$c->first_name} {$c->last_name}"))->implode(' | ');
            $csv .= sprintf(
                "%s,%d,\"%s\",\"%s\"\n",
                $nsn,
                $group->count(),
                $group->pluck('id')->implode(' '),
                str_replace('"', '""', $names),
            );
        }
        $path = "{$dir}/duplicate-clients.csv";
        Storage::disk('local')->put($path, $csv);
        $this->ctx->count('report', 'duplicate_phone_groups', $rows->count());

        return $path;
    }

    private function render(
        string $label,
        ?string $dumpFile,
        string $finishedAt,
        ?array $verification,
        Collection $warnings,
        ?string $duplicatesCsv,
    ): string {
        $lines = [];
        $lines[] = "# Legacy import run {$label}".($this->ctx->dryRun ? ' (DRY RUN — nothing written)' : '');
        $lines[] = '';
        $lines[] = "- Started: {$this->startedAt} · finished: {$finishedAt}";
        $lines[] = '- Dump: '.($dumpFile ?? '(already staged)');
        $lines[] = '- Target DB: '.$this->ctx->target->getDatabaseName().' · staging DB: '.$this->ctx->legacy->getDatabaseName();
        $lines[] = '';

        $lines[] = '## Counters';
        $lines[] = '';
        $lines[] = '| phase | '.implode(' | ', ['inserted', 'updated', 'skipped', 'adopted', 'ignored', 'other']).' |';
        $lines[] = '|---|---|---|---|---|---|---|';
        foreach ($this->ctx->counters() as $phase => $counters) {
            $known = ['inserted', 'updated', 'skipped', 'adopted', 'ignored'];
            $other = collect($counters)->except($known)->map(fn ($n, $k) => "{$k}:{$n}")->implode(' ');
            $lines[] = "| {$phase} | ".implode(' | ', array_map(fn ($k) => (string) ($counters[$k] ?? 0), $known))." | {$other} |";
        }
        $lines[] = '';

        if ($verification !== null) {
            $lines[] = '## Verification (§8) — '.($verification['passed'] ? '✅ ALL CHECKS PASS' : '❌ FAILURES');
            $lines[] = '';
            $lines[] = '| check | expected | actual | |';
            $lines[] = '|---|---|---|---|';
            foreach ($verification['checks'] as $check) {
                $lines[] = "| {$check['name']} | {$check['expected']} | {$check['actual']} | ".($check['pass'] ? '✅' : '❌').' |';
            }
            $lines[] = '';
            foreach ($verification['info'] as $info) {
                $lines[] = "- {$info}";
            }
            $lines[] = '';
        }

        $lines[] = '## Warnings ledger (§7) — '.count($this->ctx->warnings()).' total';
        $lines[] = '';
        foreach ($warnings as $category => $group) {
            $lines[] = "### {$category} ({$group->count()})";
            foreach ($group->take(60) as $warning) {
                $lines[] = '- '.$warning['message'];
            }
            if ($group->count() > 60) {
                $lines[] = '- … and '.($group->count() - 60).' more (full list in legacy_import_runs.warnings).';
            }
            $lines[] = '';
        }

        if ($duplicatesCsv !== null) {
            $lines[] = "Duplicate-phone clients: see `storage/app/{$duplicatesCsv}` — resolve in-app via the duplicates workflow.";
        }

        return implode("\n", $lines)."\n";
    }
}
