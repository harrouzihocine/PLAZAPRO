<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An in-site plan may now go out WITHOUT an agent — it sits in the dispatch
 * pool until a visits.dispatch holder assigns it from the weekly board — so
 * next_actions.assigned_to becomes nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('next_actions', function (Blueprint $table) {
            $table->foreignId('assigned_to')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('next_actions', function (Blueprint $table) {
            $table->foreignId('assigned_to')->nullable(false)->change();
        });
    }
};
