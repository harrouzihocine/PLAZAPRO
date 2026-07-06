<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Unit type" was really a project-level attribute (open / closed / semi-closed
 * residence), not a per-apartment one — the per-apartment distinction is the
 * room number. This moves the type from the unit to its project (location),
 * renamed "Project type". Existing unit types are backfilled onto their location
 * (the most common type among the location's units wins) before the column is
 * dropped from units. See 2026_07_21_100001 for the matching list rename.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->foreignId('type_id')->nullable()->after('commune_id')
                ->constrained('dynamic_list_items')->nullOnDelete();
        });

        // Backfill: give each location the most frequent (modal) type among its
        // units. A single project type is the intent, so the majority value wins.
        $modal = DB::table('units')
            ->select('location_id', 'type_id', DB::raw('COUNT(*) as c'))
            ->whereNotNull('type_id')
            ->groupBy('location_id', 'type_id')
            ->orderBy('location_id')
            ->orderByDesc('c')
            ->get();

        $assigned = [];
        foreach ($modal as $row) {
            if (isset($assigned[$row->location_id])) {
                continue; // already took this location's top type
            }
            $assigned[$row->location_id] = true;
            DB::table('locations')->where('id', $row->location_id)->update(['type_id' => $row->type_id]);
        }

        Schema::table('units', function (Blueprint $table) {
            $table->dropForeign(['type_id']);
            $table->dropColumn('type_id');
        });
    }

    public function down(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->foreignId('type_id')->nullable()->after('reference')
                ->constrained('dynamic_list_items')->nullOnDelete();
        });

        Schema::table('locations', function (Blueprint $table) {
            $table->dropForeign(['type_id']);
            $table->dropColumn('type_id');
        });
    }
};
