<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A location (development) gets an optional "cover" picture — the hero image on
 * its card and detail page. It points at a row in the polymorphic `media` table
 * (uploaded to the `photos` collection), so no new storage path is introduced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->foreignId('cover_media_id')->nullable()->after('longitude')
                ->constrained('media')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cover_media_id');
        });
    }
};
