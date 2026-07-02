<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Non-destructive reconciliation for databases created BEFORE the geography
 * feature: rename locations/desires `area_id` → `wilaya_id`, add `commune_id`,
 * and drop the superseded `areas` dynamic list. Fully GUARDED so it is a no-op
 * on databases that already have the new schema (fresh installs, where the
 * create_locations/create_desires migrations already ship `wilaya_id`).
 *
 * The old `area_id` values pointed at the `areas` list items, which this same
 * migration removes — so those pointers are cleared (set null) during the rename;
 * rows themselves are preserved. Re-pick the wilaya in-app afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('locations', 'area_id') && ! Schema::hasColumn('locations', 'wilaya_id')) {
            Schema::table('locations', function (Blueprint $table) {
                $table->dropForeign('locations_area_id_foreign');
                $table->dropIndex('locations_area_id_index');
            });
            DB::table('locations')->update(['area_id' => null]);
            Schema::table('locations', fn (Blueprint $table) => $table->renameColumn('area_id', 'wilaya_id'));
            Schema::table('locations', function (Blueprint $table) {
                $table->foreign('wilaya_id')->references('id')->on('wilayas')->nullOnDelete();
                $table->foreignId('commune_id')->nullable()->after('wilaya_id')->constrained('communes')->nullOnDelete();
                $table->index('wilaya_id');
                $table->index('commune_id');
            });
        }

        if (Schema::hasColumn('desires', 'area_id') && ! Schema::hasColumn('desires', 'wilaya_id')) {
            Schema::table('desires', fn (Blueprint $table) => $table->dropForeign('desires_area_id_foreign'));
            DB::table('desires')->update(['area_id' => null]);
            Schema::table('desires', fn (Blueprint $table) => $table->renameColumn('area_id', 'wilaya_id'));
            Schema::table('desires', function (Blueprint $table) {
                $table->foreign('wilaya_id')->references('id')->on('wilayas')->nullOnDelete();
                $table->foreignId('commune_id')->nullable()->after('wilaya_id')->constrained('communes')->nullOnDelete();
                $table->index('wilaya_id');
            });
        }

        // Retire the flat `areas` dynamic list (superseded by the wilayas/communes tables).
        $areas = DB::table('dynamic_lists')->where('key', 'areas')->first();
        if ($areas) {
            DB::table('dynamic_list_items')->where('dynamic_list_id', $areas->id)->delete();
            DB::table('dynamic_lists')->where('id', $areas->id)->delete();
        }
    }

    public function down(): void
    {
        // Forward-only data reconciliation; no rollback.
    }
};
