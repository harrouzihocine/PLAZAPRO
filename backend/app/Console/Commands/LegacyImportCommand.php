<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\LegacyImport\Derivation\DerivationPass;
use App\Modules\LegacyImport\Importers\BookingImporter;
use App\Modules\LegacyImport\Importers\BoxLocalImporter;
use App\Modules\LegacyImport\Importers\CallImporter;
use App\Modules\LegacyImport\Importers\ClientImporter;
use App\Modules\LegacyImport\Importers\ClientProjectImporter;
use App\Modules\LegacyImport\Importers\DesireImporter;
use App\Modules\LegacyImport\Importers\DynamicListReconciler;
use App\Modules\LegacyImport\Importers\LocationImporter;
use App\Modules\LegacyImport\Importers\MediaImporter;
use App\Modules\LegacyImport\Importers\NextActionImporter;
use App\Modules\LegacyImport\Importers\ProjectViewerImporter;
use App\Modules\LegacyImport\Importers\ShortlistImporter;
use App\Modules\LegacyImport\Importers\SystemUserImporter;
use App\Modules\LegacyImport\Importers\TaskImporter;
use App\Modules\LegacyImport\Importers\UnitImporter;
use App\Modules\LegacyImport\Importers\UserImporter;
use App\Modules\LegacyImport\Importers\VersementImporter;
use App\Modules\LegacyImport\Importers\VisitImporter;
use App\Modules\LegacyImport\Importers\WilayaMatcher;
use App\Modules\LegacyImport\Reporting\RunReporter;
use App\Modules\LegacyImport\Reporting\Verification;
use App\Modules\LegacyImport\Support\DynamicListResolver;
use App\Modules\LegacyImport\Support\ImportContext;
use App\Modules\LegacyImport\Support\StagingLoader;
use App\Modules\LegacyImport\Support\Transform;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;

/**
 * php artisan legacy:import — idempotent sync of the legacy CRM into PLAZA
 * (PLAZA_MIGRATION_PLAN.md). Raw query-builder writes only: no model events,
 * observers, notifications or activity logging fire (besides the explicit
 * activity_log rows of §5.12/§5.14). Never deletes target rows. Re-runnable
 * against fresh dumps until cutover (§2).
 */
class LegacyImportCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'legacy:import
        {--load= : Load a dump from database/data into the staging schema first (newest when no name given)}
        {--only= : Comma-separated phase list (see --list) — derivation/verification run only when included or when no filter is given}
        {--dry-run : Full transform + counters + warnings, zero writes}
        {--chunk=500 : Source rows per chunk/transaction}
        {--force : Skip the production confirmation prompt}
        {--list : Print the phase order and exit}';

    protected $description = 'Import/sync the legacy CRM dump into PLAZA (idempotent, re-runnable)';

    /** §2.3 — hard dependency order. */
    private const PHASES = [
        'system_user' => SystemUserImporter::class,
        'wilayas' => WilayaMatcher::class,
        'lists' => DynamicListReconciler::class,
        'users' => UserImporter::class,
        'locations' => LocationImporter::class,
        'units' => UnitImporter::class,
        'box_locals' => BoxLocalImporter::class,
        'clients' => ClientImporter::class,
        'client_projects' => ClientProjectImporter::class,
        'project_viewers' => ProjectViewerImporter::class,
        'desires' => DesireImporter::class,
        'calls' => CallImporter::class,
        'visits' => VisitImporter::class,
        'next_actions' => NextActionImporter::class,
        'tasks' => TaskImporter::class,
        'shortlist' => ShortlistImporter::class,
        'bookings' => BookingImporter::class,
        'versements' => VersementImporter::class,
        'media' => MediaImporter::class,
    ];

    public function handle(): int
    {
        if ($this->option('list')) {
            $this->line(implode(', ', array_keys(self::PHASES)).', derive, verify');

            return self::SUCCESS;
        }

        $ctx = new ImportContext;
        $ctx->dryRun = (bool) $this->option('dry-run');
        $ctx->chunkSize = max(1, (int) $this->option('chunk'));

        $this->line(sprintf(
            'Target DB: <info>%s</info> · staging DB: <info>%s</info> · %s',
            $ctx->target->getDatabaseName(),
            $ctx->legacy->getDatabaseName(),
            $ctx->dryRun ? '<comment>DRY RUN (zero writes)</comment>' : 'live run',
        ));

        // Guard rail: this command rewrites pipeline data — in production it
        // must be a deliberate act (--force, like migrate --force).
        if (! $ctx->dryRun && ! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $dumpFile = null;
        if ($this->input->hasParameterOption('--load')) {
            $dumpFile = (new StagingLoader($ctx))->load($this->option('load'));
            $this->info("Staged dump: {$dumpFile}");
        }

        $only = $this->option('only') !== null
            ? array_map('trim', explode(',', (string) $this->option('only')))
            : null;
        if ($only !== null) {
            $unknown = array_diff($only, array_keys(self::PHASES), ['derive', 'verify']);
            if ($unknown !== []) {
                $this->error('Unknown phase(s): '.implode(', ', $unknown).' — see --list.');

                return self::FAILURE;
            }
        }

        $transform = new Transform((float) $ctx->cfg('price_multiplier'));
        $lists = new DynamicListResolver($ctx, $transform);
        $reporter = new RunReporter($ctx);
        $reporter->start($dumpFile);

        foreach (self::PHASES as $phase => $class) {
            if ($only !== null && ! in_array($phase, $only, true)) {
                continue;
            }
            $startedAt = microtime(true);
            (new $class($ctx, $lists, $transform))->run();
            $counters = collect($ctx->counters()[$phase] ?? [])
                ->map(fn ($n, $k) => "{$k} {$n}")->implode(', ');
            $this->line(sprintf('  <info>%-16s</info> %s (%.1fs)', $phase, $counters ?: '—', microtime(true) - $startedAt));
        }

        if ($only === null || in_array('derive', $only, true)) {
            $startedAt = microtime(true);
            (new DerivationPass($ctx))->run();
            $counters = collect($ctx->counters()['derive'] ?? [])->map(fn ($n, $k) => "{$k} {$n}")->implode(', ');
            $this->line(sprintf('  <info>%-16s</info> %s (%.1fs)', 'derive', $counters ?: '—', microtime(true) - $startedAt));
        }

        $verification = null;
        if (($only === null || in_array('verify', $only, true)) && ! $ctx->dryRun) {
            $verification = (new Verification($ctx))->run();
            foreach ($verification['checks'] as $check) {
                $this->line(sprintf(
                    '  %s %s: expected %s, got %s',
                    $check['pass'] ? '<info>✔</info>' : '<error>✘</error>',
                    $check['name'],
                    $check['expected'],
                    $check['actual'],
                ));
            }
            foreach ($verification['info'] as $info) {
                $this->line("  · {$info}");
            }
        } elseif ($ctx->dryRun) {
            $this->comment('Verification skipped on dry-run (nothing was written).');
        }

        $reportPath = $reporter->finish($verification, $dumpFile);
        $this->newLine();
        $this->info('Warnings: '.count($ctx->warnings())." · report: storage/app/{$reportPath}");

        if ($verification !== null && ! $verification['passed']) {
            $this->error('VERIFICATION FAILED — see the report.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
