<?php

declare(strict_types=1);

use App\Modules\Inventory\Enums\MediaCollection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Normalise `media.collection` from the old free-form value (`gallery` and, per
 * the original schema comment, `brochure`/`floorplan`) into the enforced
 * MediaCollection vocabulary, then make the column NOT NULL with a default so
 * every asset lands in a known tab. See App\Modules\Inventory\Enums\MediaCollection.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Map known legacy values, then sweep anything else (incl. NULL) to the
        // catch-all so no row is left outside the enum before we lock the column.
        DB::table('media')->where('collection', 'gallery')->update(['collection' => 'photos']);
        DB::table('media')->where('collection', 'brochure')->update(['collection' => 'presentations']);
        DB::table('media')->where('collection', 'floorplan')->update(['collection' => 'plans']);

        DB::table('media')
            ->whereNull('collection')
            ->orWhereNotIn('collection', MediaCollection::values())
            ->update(['collection' => MediaCollection::default()->value]);

        Schema::table('media', function (Blueprint $table) {
            $table->string('collection')->default(MediaCollection::default()->value)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->string('collection')->nullable()->default(null)->change();
        });
    }
};
