<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fast checkbox checklist logged against a visit — an array of dynamic-list item
 * ids (`office_visit_checklist` for office visits, `insite_outcomes` context for
 * field visits). JSON so a correction (supersedeWith → replicate) copies it intact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->json('checklist')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->dropColumn('checklist');
        });
    }
};
