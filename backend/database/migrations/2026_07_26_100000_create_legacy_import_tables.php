<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency core of the legacy CRM importer (php artisan legacy:import,
 * PLAZA_MIGRATION_PLAN.md §2.2). legacy_map is the source-row → target-row
 * ledger: re-runs against fresh dumps insert new rows, update changed rows
 * (row_hash diff) and skip unchanged ones — never delete, never duplicate.
 * Synthesized rows (no 1:1 legacy source) use virtual source tables like
 * "synth:deal_from_booking". legacy_import_runs records one row per sync with
 * its counters and warning ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_map', function (Blueprint $table) {
            $table->id();
            $table->string('source_table')->index();
            $table->unsignedBigInteger('source_id')->index();
            $table->string('target_table');
            $table->unsignedBigInteger('target_id');
            // md5 of the transformed payload — hash equal means skip on re-run.
            $table->string('row_hash', 32);
            // True when the target row pre-existed (hand-entered PERLA / EL
            // MOURDJAN, overlapping user emails) and was matched, not created.
            // Adopted rows only ever get NULL columns filled — the importer
            // never overwrites values it did not write (§5.5 one-way enrich).
            $table->boolean('adopted')->default(false);
            $table->timestamps();

            $table->unique(['source_table', 'source_id']);
            $table->index(['target_table', 'target_id']);
        });

        Schema::create('legacy_import_runs', function (Blueprint $table) {
            $table->id();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->string('dump_file')->nullable();
            $table->boolean('dry_run')->default(false);
            $table->json('stats')->nullable();
            $table->json('warnings')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_import_runs');
        Schema::dropIfExists('legacy_map');
    }
};
