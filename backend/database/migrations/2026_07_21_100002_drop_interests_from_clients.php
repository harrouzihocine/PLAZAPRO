<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the client `interests` column. The "property interests" capture was
 * removed from the product (qualification happens through the shortlist and the
 * desire profile instead), so the column and its `property_interests` dynamic
 * list are no longer used. Reverses 2026_07_08_100000.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('interests');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->json('interests')->nullable()->after('notes');
        });
    }
};
