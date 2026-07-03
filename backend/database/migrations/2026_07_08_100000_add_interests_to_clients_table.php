<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the client is shopping for — an array of `property_interests` dynamic-list
 * item ids (apartment / box / local). Captured at lead creation and used to route
 * qualification and seed the office-visit shortlist. Multi-select, so stored as JSON.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->json('interests')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('interests');
        });
    }
};
