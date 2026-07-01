<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Closes the Phase 2 gap: reservations.client_project_id was created nullable
 * with NO foreign key because client_projects did not exist yet. Now that Phase 3
 * has created it, constrain the column. See docs/database/02-inventory.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->foreign('client_project_id')
                ->references('id')->on('client_projects')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropForeign(['client_project_id']);
        });
    }
};
