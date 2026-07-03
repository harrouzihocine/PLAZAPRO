<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fast checkbox talking-points logged against a call — an array of `call_topics`
 * dynamic-list item ids. JSON so a correction (supersedeWith → replicate) copies
 * the whole selection intact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->json('topics')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->dropColumn('topics');
        });
    }
};
