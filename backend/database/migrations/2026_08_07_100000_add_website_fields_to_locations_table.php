<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public-website controls per project. A location is invisible to the public
 * showcase until `is_published`; `show_prices`/`show_availability` let the
 * owner decide, per project, how much the public sees. The marketing_* JSON
 * columns hold {en,fr,ar} copy written for visitors (distinct from the
 * internal `description`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->boolean('is_published')->default(false)->after('cover_focus_y');
            $table->boolean('show_prices')->default(true)->after('is_published');
            $table->boolean('show_availability')->default(true)->after('show_prices');
            $table->json('marketing_tagline')->nullable()->after('show_availability');
            $table->json('marketing_description')->nullable()->after('marketing_tagline');
            // Construction advancement (%) shown on the public site (UI later).
            $table->unsignedTinyInteger('construction_progress')->nullable()->after('marketing_description');

            $table->index('is_published');
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropIndex(['is_published']);
            $table->dropColumn([
                'is_published', 'show_prices', 'show_availability',
                'marketing_tagline', 'marketing_description', 'construction_progress',
            ]);
        });
    }
};
