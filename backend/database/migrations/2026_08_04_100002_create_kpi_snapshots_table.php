<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily materialized KPI values, written by the nightly `kpi:snapshot` command.
 * Gives the command center its time-series curves (sales, receivables,
 * collections, inventory burn-down) for free and keeps heavy aggregates off the
 * live request path (see the catalog's implementation notes).
 *
 * One row per (snapshot_date, metric, dimension). dimension is null for the
 * company-wide figure, else a "kind:id" key — "location:5", "agent:3",
 * "unit_type:apartment". Re-running a day updateOrCreate's its rows (idempotent).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kpi_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('snapshot_date');
            $table->string('metric', 40);               // e.g. sales_value, collected, receivables_outstanding
            $table->string('dimension', 60)->nullable(); // null=company | location:5 | agent:3 | unit_type:apartment
            $table->decimal('value', 16, 2)->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['snapshot_date', 'metric', 'dimension'], 'kpi_snapshots_unique');
            $table->index(['metric', 'dimension', 'snapshot_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_snapshots');
    }
};
