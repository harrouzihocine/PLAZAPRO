<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * §2.1 — loads a legacy dump from database/data into the crm_legacy staging
 * schema (drop & recreate), executing the dump statement-by-statement over
 * PDO (no mysql CLI dependency — the app container ships a MariaDB client
 * that cannot authenticate against MySQL 8). Line-based splitting is safe for
 * mysqldump output: string values escape newlines as \n, so a statement
 * always ends with ';' at end-of-line. GENERATED ALWAYS columns are
 * neutralized (MariaDB dumps their values; MySQL 8 refuses explicit writes
 * into generated columns — staging wants them as plain data anyway).
 */
class StagingLoader
{
    public function __construct(private readonly ImportContext $ctx) {}

    /** @return string the dump filename that was loaded */
    public function load(?string $fileName): string
    {
        $dir = base_path((string) $this->ctx->cfg('dumps_path'));
        $file = $this->resolveDump($dir, $fileName);

        $connectionName = (string) $this->ctx->cfg('connection');
        $database = (string) config("database.connections.{$connectionName}.database");

        // Recreate the staging schema via the app connection (the staging
        // connection itself cannot connect while its schema is gone).
        $this->ctx->target->statement("DROP DATABASE IF EXISTS `{$database}`");
        $this->ctx->target->statement("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        DB::purge($connectionName);

        $handle = fopen($file, 'r');
        if ($handle === false) {
            throw new RuntimeException("legacy:import — cannot read dump {$file}.");
        }

        $pdo = DB::connection($connectionName)->getPdo();
        $buffer = '';
        $statements = 0;
        while (($line = fgets($handle)) !== false) {
            $trimmed = rtrim($line);
            if ($buffer === '' && ($trimmed === '' || str_starts_with($trimmed, '--'))) {
                continue;
            }
            // Generated columns → plain columns (values are in the dump).
            if ($buffer === '' || str_contains($line, 'GENERATED ALWAYS')) {
                $line = preg_replace('/ GENERATED ALWAYS AS \(.+\) (STORED|VIRTUAL)/', '', $line);
                $trimmed = rtrim($line);
            }
            $buffer .= $line;
            if (str_ends_with($trimmed, ';')) {
                $pdo->exec($buffer);
                $buffer = '';
                $statements++;
            }
        }
        fclose($handle);

        // Fresh session for the importer reads (the dump manipulated session
        // vars like TIME_ZONE and FOREIGN_KEY_CHECKS).
        DB::purge($connectionName);
        $this->ctx->refreshConnections();

        $tables = $this->ctx->legacy->table('information_schema.tables')
            ->where('table_schema', $database)->count();
        if ($tables < 30) {
            throw new RuntimeException("legacy:import — staging looks wrong: only {$tables} tables after loading {$statements} statements.");
        }

        return basename($file);
    }

    private function resolveDump(string $dir, ?string $fileName): string
    {
        if ($fileName !== null && $fileName !== '') {
            $path = str_contains($fileName, '/') ? $fileName : "{$dir}/{$fileName}";
            if (! is_file($path)) {
                throw new RuntimeException("legacy:import — dump not found: {$path}.");
            }

            return $path;
        }

        $candidates = glob("{$dir}/*.sql") ?: [];
        if ($candidates === []) {
            throw new RuntimeException("legacy:import — no *.sql dump found in {$dir}.");
        }
        // Newest by mtime; dated filenames (crm_YYYY-MM-DD.sql) tie-break by name.
        usort($candidates, fn ($a, $b) => [filemtime($b), $b] <=> [filemtime($a), $a]);

        return $candidates[0];
    }
}
