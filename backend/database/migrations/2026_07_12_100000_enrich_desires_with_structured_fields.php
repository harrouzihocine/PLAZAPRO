<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Max-detail desire profile, mirroring the unit form: the free-text floor
 * preference becomes a `floors` dynamic-list selector (floor_id), the area
 * range and minimum rooms arrive as numbers, and `desire_locations` holds the
 * preferred sites (a desire may point at several projects of interest).
 * Existing floor_pref values are matched to a floors item by label when
 * possible; the raw text stays in floor_pref as a fallback record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('desires', function (Blueprint $table) {
            $table->foreignId('floor_id')->nullable()->after('type_id')
                ->constrained('dynamic_list_items')->nullOnDelete();
            $table->decimal('area_min', 10, 2)->nullable()->after('floor_pref');
            $table->decimal('area_max', 10, 2)->nullable()->after('area_min');
            $table->unsignedTinyInteger('rooms_min')->nullable()->after('area_max');
        });

        Schema::create('desire_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('desire_id')->constrained('desires')->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['desire_id', 'location_id']);
        });

        // Best-effort: map the old free-text floor preference onto the floors
        // dynamic list when the text happens to equal an item's label.
        $floors = DB::table('dynamic_list_items')
            ->join('dynamic_lists', 'dynamic_lists.id', '=', 'dynamic_list_items.dynamic_list_id')
            ->where('dynamic_lists.key', 'floors')
            ->pluck('dynamic_list_items.id', 'dynamic_list_items.label');

        foreach ($floors as $label => $id) {
            DB::table('desires')
                ->whereNull('floor_id')
                ->whereRaw('LOWER(TRIM(floor_pref)) = ?', [mb_strtolower(trim((string) $label))])
                ->update(['floor_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('desire_locations');

        Schema::table('desires', function (Blueprint $table) {
            $table->dropConstrainedForeignId('floor_id');
            $table->dropColumn(['area_min', 'area_max', 'rooms_min']);
        });
    }
};
