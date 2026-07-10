<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The dispatch lifecycle a field visit walks after assignment:
 *
 *   assigned → accepted → en route → arrived (geofence or manual) → departed
 *
 * Every step is a nullable timestamp — the visit's status is derived from
 * which stamps exist, never stored twice. `assigned_at` restarts (and the
 * later stamps reset) whenever the visit moves to another agent, so the
 * accept-SLA clock always measures the CURRENT agent. `declined_at` +
 * `decline_reason` are stamped just before a decline returns the plan to the
 * dispatch pool (the visit row is then cancelled — history keeps the why).
 * The two `*_alerted_at` columns make the dispatch sweeper's nudges one-shot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->timestamp('assigned_at')->nullable()->after('agent_id');
            $table->timestamp('accepted_at')->nullable()->after('assigned_at');
            $table->timestamp('en_route_at')->nullable()->after('accepted_at');
            $table->timestamp('arrived_at')->nullable()->after('en_route_at');
            $table->timestamp('departed_at')->nullable()->after('arrived_at');
            $table->timestamp('declined_at')->nullable()->after('departed_at');
            $table->string('decline_reason')->nullable()->after('declined_at');
            $table->timestamp('acceptance_alerted_at')->nullable()->after('decline_reason');
            $table->timestamp('late_alerted_at')->nullable()->after('acceptance_alerted_at');
        });
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->dropColumn([
                'assigned_at', 'accepted_at', 'en_route_at', 'arrived_at', 'departed_at',
                'declined_at', 'decline_reason', 'acceptance_alerted_at', 'late_alerted_at',
            ]);
        });
    }
};
