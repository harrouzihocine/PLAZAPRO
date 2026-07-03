<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Visits are now materialized FROM next actions (choosing "office visit" /
 * "in-site visit" as the next step IS the scheduling step) — `next_action_id`
 * links each auto-created visit to the plan that spawned it, so correcting the
 * plan can cancel/recreate its pending visits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->foreignId('next_action_id')->nullable()->after('agent_id')
                ->constrained('next_actions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->dropConstrainedForeignId('next_action_id');
        });
    }
};
