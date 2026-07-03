<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records which user opened each project. Drives the projects.view_all
 * permission: without it a user sees only the projects they created (plus the
 * ones they were added to as a viewer). nullOnDelete keeps projects intact if
 * the creating user is later removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_projects', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->after('client_id')
                ->constrained('users')->nullOnDelete();
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::table('client_projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
        });
    }
};
