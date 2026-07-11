<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the redundant `rooms_min` number from desires. The multi-valued
 * `room_number_ids` criterion (F2 / F3 …) already captures how many rooms the
 * client wants and is the field matching actually uses; the free minimum was a
 * duplicate that never fed the match. Forward-only removal — matching, the API
 * resource and the capture form no longer reference it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('desires', function (Blueprint $table) {
            $table->dropColumn('rooms_min');
        });
    }

    public function down(): void
    {
        Schema::table('desires', function (Blueprint $table) {
            $table->unsignedTinyInteger('rooms_min')->nullable()->after('area_max');
        });
    }
};
