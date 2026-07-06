<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An in-site plan may now target SPECIFIC apartment(s) rather than the whole
 * shortlist: when a field agent concludes a visit into another in-site visit,
 * they pick "same apartment" (re-visit this unit) or "another apartment" (a new
 * unit). The chosen unit ids ride on the plan so the target survives the dispatch
 * pool — the visits materialize (now or on assignment) for exactly those units.
 * Null keeps the legacy "every shortlisted unit" behaviour (office / call plans).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('next_actions', function (Blueprint $table) {
            $table->json('target_unit_ids')->nullable()->after('assigned_to');
        });
    }

    public function down(): void
    {
        Schema::table('next_actions', function (Blueprint $table) {
            $table->dropColumn('target_unit_ids');
        });
    }
};
