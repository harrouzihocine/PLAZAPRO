<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Path-tracking follow-ups on the dispatch GPS layer:
 *
 *  - agent_mileage_days: one row per agent per duty day — km driven, fixes,
 *    duty minutes. Aggregated nightly from agent_positions BEFORE the
 *    retention prune eats the breadcrumbs, so mileage history outlives them.
 *  - visits.offroute_alerted_at: the one-shot stamp for the "agent drifted
 *    off the route to the site" dispatcher alert, reset on reassignment like
 *    its acceptance/late siblings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_mileage_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->decimal('km', 8, 2)->default(0);
            $table->unsignedInteger('fixes')->default(0);
            $table->unsignedInteger('duty_minutes')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'day']);
            $table->index('day');
        });

        Schema::table('visits', function (Blueprint $table) {
            $table->timestamp('offroute_alerted_at')->nullable()->after('late_alerted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_mileage_days');
        Schema::table('visits', function (Blueprint $table) {
            $table->dropColumn('offroute_alerted_at');
        });
    }
};
