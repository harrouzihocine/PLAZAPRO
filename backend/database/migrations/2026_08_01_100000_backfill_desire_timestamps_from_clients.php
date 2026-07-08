<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The legacy importer created desires without created_at/updated_at, so the
 * Matches board's waiting-since date filter silently dropped every imported
 * desire (NULL never satisfies a date comparison). The client's created_at is
 * the honest stand-in: a legacy desire was captured with its client. Idempotent
 * (only NULL rows are touched); nothing displays these timestamps today, so
 * the only behavior change is the filter seeing the rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE desires d
            JOIN clients c ON c.id = d.client_id
            SET d.created_at = c.created_at,
                d.updated_at = COALESCE(d.updated_at, c.created_at)
            WHERE d.created_at IS NULL
        SQL);
    }

    public function down(): void
    {
        // Irreversible by design: the original NULLs carry no information worth
        // restoring, and re-nulling would break the date filter again.
    }
};
