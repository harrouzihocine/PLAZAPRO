<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Concerns/objections the client raised on a visit — an array of `objection_reasons`
 * dynamic-list item ids. An in-site visit carries a unit_id, so these objections
 * attribute to the specific unit in the Voice-of-Client analytics. JSON so a
 * correction (supersedeWith → replicate) copies the whole selection intact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->json('objections')->nullable()->after('checklist');
        });
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->dropColumn('objections');
        });
    }
};
