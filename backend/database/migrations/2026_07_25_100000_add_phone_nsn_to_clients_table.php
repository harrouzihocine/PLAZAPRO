<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Adds an indexed, normalized phone column (the national significant number — the
 * last 9 digits after stripping non-digits) so the duplicate-client guard, which
 * runs on every client create, resolves with an index seek instead of a
 * full-table function scan. STORED + indexed generated column (MySQL 8), kept in
 * sync automatically from `phone`. The free-text search stays a substring LIKE
 * (inherently un-indexable) — this targets the exact-match lookup only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function ($table) {
            $table->string('phone_nsn', 9)
                ->storedAs("RIGHT(REGEXP_REPLACE(COALESCE(phone, ''), '[^0-9]', ''), 9)")
                ->nullable()
                ->after('phone');
            $table->index('phone_nsn');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function ($table) {
            $table->dropIndex(['phone_nsn']);
            $table->dropColumn('phone_nsn');
        });
    }
};
