<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * visited_at = when the visit actually took place, as stated by the agent on
 * the completion form (required for in-site logs). It is oversight data:
 * completed_at records when the log was FILLED, visited_at when the client was
 * actually on site — the gap between them tells the manager how promptly field
 * agents log their work.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->timestamp('visited_at')->nullable()->after('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->dropColumn('visited_at');
        });
    }
};
