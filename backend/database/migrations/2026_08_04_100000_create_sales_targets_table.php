<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales targets backing the KPI command center's "target attainment" metrics.
 * A target is period-scoped and dimensioned: company-wide, or narrowed to one
 * agent / development (location) / unit type. Unlike the domain records this is
 * editable configuration — it carries no cancel-and-duplicate lifecycle.
 *
 * scope        company | agent | location | unit_type
 * scope_id     the agent/location id (null for company / a unit_type slug lives
 *              in scope_key instead — kept simple: unit_type uses scope_key).
 * metric       sales_value | units_sold | collections
 * period_start the first day of the target window (month/quarter/year start).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_targets', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 20)->default('company'); // company|agent|location|unit_type
            $table->unsignedBigInteger('scope_id')->nullable(); // agent/location id
            $table->string('scope_key', 50)->nullable();     // unit_type slug when scope=unit_type
            $table->string('period_type', 10);               // month|quarter|year
            $table->date('period_start');
            $table->string('metric', 30);                    // sales_value|units_sold|collections
            $table->decimal('target_amount', 14, 2);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['scope', 'scope_id', 'scope_key', 'period_type', 'period_start', 'metric'],
                'sales_targets_unique',
            );
            $table->index(['metric', 'period_type', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_targets');
    }
};
