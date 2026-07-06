<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Concerns/objections the client raised on a call — an array of `objection_reasons`
 * dynamic-list item ids. Distinct from `topics` (what was discussed): objections are
 * the "why not" signals mined by the Voice-of-Client analytics. JSON so a correction
 * (supersedeWith → replicate) copies the whole selection intact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->json('objections')->nullable()->after('topics');
        });
    }

    public function down(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->dropColumn('objections');
        });
    }
};
