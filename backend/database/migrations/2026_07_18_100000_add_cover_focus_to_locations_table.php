<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The focal point of the cover picture (0-100 %, default centered). Lets the user
 * reposition which part of the image shows in the fixed-ratio card/hero frame —
 * applied as CSS object-position, so the file itself is never re-encoded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->unsignedTinyInteger('cover_focus_x')->default(50)->after('cover_media_id');
            $table->unsignedTinyInteger('cover_focus_y')->default(50)->after('cover_focus_x');
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn(['cover_focus_x', 'cover_focus_y']);
        });
    }
};
