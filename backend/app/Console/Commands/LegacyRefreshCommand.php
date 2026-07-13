<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * php artisan legacy:refresh — clean rebuild of the business data from a fresh
 * legacy CRM dump (approved plan: docs/database/PLAZA_MIGRATION_PLAN 2.md,
 * decisions of 2026-07-13). In order:
 *
 *   1. Print the active DB connection and require the typed phrase
 *      "wipe <database>" (plus --confirm) before anything destructive.
 *   2. Timestamped mariadb-dump backup of the target DB to
 *      storage/app/backups/ — abort when empty or truncated.
 *   3. Archive PLAZA-native rows (not in legacy_map) of every wiped table to
 *      a JSON file, for manual re-entry after the refresh.
 *   4. Snapshot KEEP tables (row count + CHECKSUM TABLE).
 *   5. Wipe the approved table list with DELETE (never DROP/TRUNCATE) inside
 *      one transaction with FK checks off; partial rules: media keeps
 *      website_space rows, activity_log keeps non-business subjects,
 *      web_leads survives with its inventory FKs nulled. legacy_map keeps
 *      entries whose target table is KEPT (users/wilayas/dynamic_list_items —
 *      vital: staff emails were renamed after the July import, so re-adoption
 *      by email would duplicate 16 accounts) and clears the rest.
 *   6. Re-verify the KEEP snapshot — any drift aborts before the import.
 *   7. Chain php artisan legacy:import --load=<dump> (sync + derivation +
 *      §8 verification) and fail loudly when verification fails.
 *
 * Everything is logged to storage/app/legacy-import/refresh-<ts>.md.
 * --dry-run walks all steps, prints per-table counts and writes nothing.
 */
class LegacyRefreshCommand extends Command
{
    protected $signature = 'legacy:refresh
        {--load= : Legacy dump in database/data to stage & import (newest when omitted)}
        {--dry-run : Report what would happen — zero writes, no backup, no import}
        {--confirm : Required for the live run (destructive)}';

    protected $description = 'Wipe business data and rebuild it from a fresh legacy CRM dump (backup + KEEP verification + import chain)';

    /**
     * Approved WIPE list (2026-07-13) — DELETE order is irrelevant (FK checks
     * are off inside the transaction) but reads child → parent for clarity.
     */
    private const WIPE = [
        // pipeline
        'calls', 'visits', 'next_actions', 'tasks', 'reminders', 'call_requests',
        'shortlist_items', 'deal_items', 'deals', 'reservations', 'versements', 'payment_schedules',
        'desire_list_items', 'desire_communes', 'desire_wilayas', 'desire_locations', 'desires',
        'client_project_viewers', 'client_detail_grants', 'client_duplicate_requests',
        'client_projects', 'clients',
        // inventory (owner decision: full wipe, re-imported from legacy)
        'location_payment_methods', 'units', 'boxes', 'locations',
        // comms & derived (regenerable or dangling onto wiped subjects)
        'notifications', 'kpi_snapshots',
        'message_reactions', 'message_attachments', 'messages', 'conversation_user', 'conversations',
        'documents', 'media_share_items', 'media_shares',
    ];

    /** media: wiped EXCEPT rows attached to the (kept) website_spaces. */
    private const MEDIA_KEEP_MORPH = 'website_space';

    /**
     * KEEP tables verified untouched (count + CHECKSUM TABLE). web_leads is
     * deliberately absent: it is kept but modified (inventory FKs nulled).
     */
    private const KEEP = [
        'users', 'roles', 'permissions', 'permission_role', 'departments',
        'dynamic_lists', 'dynamic_list_items', 'wilayas', 'communes', 'app_settings',
        'migrations', 'password_reset_tokens', 'personal_access_tokens', 'user_drafts',
        'device_tokens', 'duty_sessions', 'agent_positions', 'agent_mileage_days',
        'sales_targets', 'project_costs', 'website_spaces', 'web_stat_events',
        'idempotency_keys', 'legacy_import_runs',
    ];

    /** KEEP tables that change legitimately while the app runs — warn, not fail. */
    private const KEEP_VOLATILE = ['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs'];

    /** Tables whose native (not-in-legacy_map) rows go to the JSON archive. */
    private const ARCHIVE_NATIVE = [
        'clients', 'client_projects', 'calls', 'visits', 'next_actions', 'tasks',
        'deals', 'deal_items', 'reservations', 'versements', 'shortlist_items',
        'units', 'locations', 'media',
    ];

    /** @var list<string> report lines (mirrored to the md report). */
    private array $report = [];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $db = DB::connection();
        $dbName = $db->getDatabaseName();
        $stagingName = (string) config('database.connections.'.config('legacy_import.connection').'.database');
        $ts = now()->format('Ymd-His');

        $this->note(sprintf(
            'Target DB: **%s** (connection `%s`, host %s) · staging DB: **%s** · %s',
            $dbName,
            (string) config('database.default'),
            (string) $db->getConfig('host'),
            $stagingName,
            $dryRun ? 'DRY RUN (zero writes)' : 'LIVE RUN',
        ));

        if (! $dryRun) {
            if (! $this->option('confirm')) {
                $this->error('Refusing: the live run requires --confirm.');

                return self::FAILURE;
            }
            $phrase = "wipe {$dbName}";
            if ($this->ask("This will DELETE business data in '{$dbName}'. Type '{$phrase}' to proceed") !== $phrase) {
                $this->error('Confirmation phrase mismatch — aborted, nothing was touched.');

                return self::FAILURE;
            }
        }

        // ---- 2. backup ---------------------------------------------------
        if (! $dryRun) {
            $backup = $this->backup($db, $dbName, $ts);
            if ($backup === null) {
                return self::FAILURE;
            }
        } else {
            $this->note('Backup: skipped on dry-run (live run writes storage/app/backups/refresh-'.$dbName.'-{ts}.sql).');
        }

        // ---- 3. native archive -------------------------------------------
        $this->archiveNativeRows($db, $ts, $dryRun);

        // ---- 4. KEEP snapshot --------------------------------------------
        $before = $this->snapshotKeep($db);
        $this->note('KEEP snapshot: '.count($before).' tables (count + checksum).');

        // ---- 5. wipe ------------------------------------------------------
        $this->wipe($db, $dryRun);

        // ---- 6. KEEP verification ----------------------------------------
        if (! $this->verifyKeep($db, $before)) {
            $this->finishReport($ts, $dryRun);
            $this->error('KEEP verification failed AFTER the wipe — import NOT started. Restore from the backup and investigate.');

            return self::FAILURE;
        }
        $this->note('KEEP verification: all tables identical (volatile plumbing excluded).');

        if ($dryRun) {
            $this->note('Dry-run complete — no backup, no wipe, no import. Re-run with --confirm for the live refresh.');
            $this->finishReport($ts, $dryRun);

            return self::SUCCESS;
        }

        // ---- 7. import chain ---------------------------------------------
        $this->note('Chaining legacy:import --load'.($this->option('load') ? '='.$this->option('load') : '').' --force …');
        $exit = Artisan::call('legacy:import', array_filter([
            '--load' => $this->option('load') ?? '',
            '--force' => true,
        ], fn ($v) => $v !== null), $this->output);

        $this->note($exit === 0
            ? 'legacy:import finished: verification GREEN.'
            : "legacy:import exited with code {$exit} — see its report; the pre-wipe backup is intact.");
        $this->finishReport($ts, $dryRun);

        return $exit === 0 ? self::SUCCESS : self::FAILURE;
    }

    /* ---------------------------------------------------------------- */
    /* Steps                                                              */
    /* ---------------------------------------------------------------- */

    /** @return string|null backup path, null on abort */
    private function backup(Connection $db, string $dbName, string $ts): ?string
    {
        $dir = storage_path('app/backups');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $path = "{$dir}/refresh-{$dbName}-{$ts}.sql";

        $process = new Process([
            'mariadb-dump',
            '-h', (string) $db->getConfig('host'),
            '-P', (string) ($db->getConfig('port') ?? 3306),
            '-u', (string) $db->getConfig('username'),
            '--skip-ssl', '--no-tablespaces', '--single-transaction', '--quick',
            '--routines', '--triggers', $dbName,
        ], null, ['MYSQL_PWD' => (string) $db->getConfig('password')], null, 3600);
        $handle = fopen($path, 'w');
        $process->run(function (string $type, string $buffer) use ($handle): void {
            if ($type === Process::OUT) {
                fwrite($handle, $buffer);
            }
        });
        fclose($handle);

        $size = is_file($path) ? filesize($path) : 0;
        $trailer = $size > 200 ? file_get_contents($path, false, null, $size - 200) : '';
        if (! $process->isSuccessful() || $size < 10_000 || ! str_contains((string) $trailer, 'Dump completed')) {
            $this->error("Backup FAILED (exit {$process->getExitCode()}, size {$size}) — aborted before any write.");
            $this->error($process->getErrorOutput());

            return null;
        }
        $this->note(sprintf('Backup: %s (%.1f MB, trailer verified).', str_replace(storage_path().'/', 'storage/', $path), $size / 1048576));

        return $path;
    }

    private function archiveNativeRows(Connection $db, string $ts, bool $dryRun): void
    {
        $archive = [];
        $total = 0;
        foreach (self::ARCHIVE_NATIVE as $table) {
            $rows = $db->table($table)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('legacy_map')
                    ->where('legacy_map.target_table', $table)
                    ->whereColumn('legacy_map.target_id', "{$table}.id"))
                ->orderBy('id')->get();
            if ($rows->isNotEmpty()) {
                $archive[$table] = $rows->map(fn ($r) => (array) $r)->all();
                $total += $rows->count();
            }
        }

        $dir = storage_path('app/'.config('legacy_import.report_dir'));
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $path = "{$dir}/native-archive-{$ts}.json";
        if (! $dryRun) {
            file_put_contents($path, json_encode($archive, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
        $counts = collect($archive)->map(fn ($rows, $t) => $t.' '.count($rows))->implode(', ');
        $this->note("Native-rows archive: {$total} rows ({$counts})".($dryRun ? ' — not written (dry-run).' : " → {$path}"));
    }

    /** @return array<string, array{count: int, checksum: ?string}> */
    private function snapshotKeep(Connection $db): array
    {
        $snapshot = [];
        foreach (array_merge(self::KEEP, self::KEEP_VOLATILE) as $table) {
            $checksum = $db->select("CHECKSUM TABLE `{$table}`")[0]->Checksum ?? null;
            $snapshot[$table] = [
                'count' => $db->table($table)->count(),
                'checksum' => $checksum !== null ? (string) $checksum : null,
            ];
        }

        return $snapshot;
    }

    private function wipe(Connection $db, bool $dryRun): void
    {
        // Business subjects in activity_log: resolve each distinct subject_type
        // (FQCN or alias) to its table; delete when that table is wiped or is
        // media (media activity rows are not worth a partial-keep rule).
        $wipedSubjectTypes = [];
        foreach ($db->table('activity_log')->distinct()->pluck('subject_type') as $type) {
            if ($type === null) {
                continue;
            }
            $class = Relation::getMorphedModel($type) ?? $type;
            if (! class_exists($class)) {
                continue;
            }
            $table = (new $class)->getTable();
            if ($table === 'media' || in_array($table, self::WIPE, true)) {
                $wipedSubjectTypes[] = $type;
            }
        }

        $plan = [];
        $plan['web_leads (inventory FKs nulled)'] = $db->table('web_leads')
            ->where(fn ($q) => $q->whereNotNull('location_id')->orWhereNotNull('unit_id')->orWhereNotNull('converted_client_id'))
            ->count();
        $plan['activity_log (business subjects)'] = $wipedSubjectTypes === [] ? 0
            : $db->table('activity_log')->whereIn('subject_type', $wipedSubjectTypes)->count();
        $plan['media (except '.self::MEDIA_KEEP_MORPH.')'] = $db->table('media')
            ->where('mediable_type', '!=', self::MEDIA_KEEP_MORPH)->count();
        foreach (self::WIPE as $table) {
            $plan[$table] = $db->table($table)->count();
        }
        $plan['legacy_map (targets not kept)'] = $db->table('legacy_map')
            ->whereNotIn('target_table', self::KEEP)->count();

        foreach ($plan as $step => $count) {
            $this->note(sprintf('  %s %-38s %d rows', $dryRun ? 'would wipe' : 'wipe', $step, $count));
        }
        if ($dryRun) {
            return;
        }

        $db->transaction(function () use ($db, $wipedSubjectTypes): void {
            $db->statement('SET FOREIGN_KEY_CHECKS=0');
            try {
                $db->table('web_leads')->update([
                    'location_id' => null, 'unit_id' => null, 'converted_client_id' => null,
                ]);
                if ($wipedSubjectTypes !== []) {
                    $db->table('activity_log')->whereIn('subject_type', $wipedSubjectTypes)->delete();
                }
                $db->table('media')->where('mediable_type', '!=', self::MEDIA_KEEP_MORPH)->delete();
                foreach (self::WIPE as $table) {
                    $db->table($table)->delete();
                }
                $db->table('legacy_map')->whereNotIn('target_table', self::KEEP)->delete();
            } finally {
                $db->statement('SET FOREIGN_KEY_CHECKS=1');
            }
        });
        $this->note('Wipe committed (single transaction, FK checks restored).');
    }

    /** @param array<string, array{count: int, checksum: ?string}> $before */
    private function verifyKeep(Connection $db, array $before): bool
    {
        $ok = true;
        foreach ($this->snapshotKeep($db) as $table => $after) {
            $unchanged = $after['count'] === $before[$table]['count']
                && $after['checksum'] === $before[$table]['checksum'];
            if ($unchanged) {
                continue;
            }
            $message = sprintf(
                'KEEP table `%s` changed during the run: %d → %d rows, checksum %s → %s',
                $table, $before[$table]['count'], $after['count'],
                $before[$table]['checksum'] ?? '—', $after['checksum'] ?? '—',
            );
            if (in_array($table, self::KEEP_VOLATILE, true)) {
                $this->note("  ⚠ {$message} (volatile plumbing — tolerated)");
            } else {
                $this->note("  ✘ {$message}");
                $ok = false;
            }
        }

        return $ok;
    }

    /* ---------------------------------------------------------------- */
    /* Reporting                                                          */
    /* ---------------------------------------------------------------- */

    private function note(string $line): void
    {
        $this->line(strip_tags(str_replace('**', '', $line)));
        $this->report[] = $line;
    }

    private function finishReport(string $ts, bool $dryRun): void
    {
        $dir = storage_path('app/'.config('legacy_import.report_dir'));
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $path = "{$dir}/refresh-".($dryRun ? 'dry-' : '')."{$ts}.md";
        file_put_contents($path, "# legacy:refresh {$ts}".($dryRun ? ' (DRY RUN)' : '')."\n\n- ".implode("\n- ", $this->report)."\n");
        $this->info('Refresh report: '.str_replace(storage_path().'/', 'storage/', $path));
    }
}
