<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-development cost figures unlocking the profitability KPIs (gross margin,
 * ROI, break-even coverage). The developer's "project" is a `locations` row
 * (building/site) — a client_project is a client's buying file, not a build —
 * so costs key on location_id, one row per development. Editable configuration,
 * no cancel-and-duplicate lifecycle. Only added if the owner wants margin
 * visibility; the profitability tab shows an empty state until a row exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->unique()->constrained('locations')->cascadeOnDelete();
            $table->decimal('land_cost', 14, 2)->default(0);
            $table->decimal('construction_cost', 14, 2)->default(0);
            $table->decimal('fees', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_costs');
    }
};
