<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A third duplicate-resolution outcome: fork_project. Besides deny and
 * share_project (join the existing project), the supervisor may spawn a SEPARATE
 * project for the finder on the same client (see the client_projects migration).
 * spawned_project_id records that new project so the resolved request links to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_duplicate_requests', function (Blueprint $table) {
            // resolution now also carries 'fork_project'; the shared_project_id
            // column stays the "join existing" target, this is the new one.
            $table->foreignId('spawned_project_id')->nullable()->after('shared_project_id')
                ->constrained('client_projects')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('client_duplicate_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('spawned_project_id');
        });
    }
};
