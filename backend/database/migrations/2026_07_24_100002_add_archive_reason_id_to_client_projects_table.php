<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The structured lost/archive reason for a project — a single `archive_reasons`
 * (a.k.a. cancellation_reasons) dynamic-list item id. The free-text label already
 * lands in `cancellation_reason`; persisting the id as well lets the Voice-of-Client
 * analytics aggregate "why deals were lost" cleanly. No hard FK, consistent with the
 * other dynamic-list references (outcome_id, topics, checklist).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_projects', function (Blueprint $table) {
            $table->unsignedBigInteger('archive_reason_id')->nullable()->after('cancellation_reason');
        });
    }

    public function down(): void
    {
        Schema::table('client_projects', function (Blueprint $table) {
            $table->dropColumn('archive_reason_id');
        });
    }
};
