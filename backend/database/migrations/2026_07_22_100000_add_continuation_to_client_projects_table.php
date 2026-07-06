<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Duplicate-resolution "separate project" outcome. When a supervisor decides two
 * agents should work the same client independently, the finder gets their OWN
 * new project on that client instead of joining the original:
 *
 *  - continued_from_project_id links it (oversight-only) to the earlier project
 *    it continues — so managers know it is not a fresh first-contact.
 *  - hidden_from_owner silos it BOTH ways: the client's own agent (creator /
 *    assigned agent) no longer sees this project through the client-owner
 *    visibility rule, and the finder (not the owner) never sees the original.
 *    Only projects.view_all holders see both sides.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_projects', function (Blueprint $table) {
            $table->foreignId('continued_from_project_id')->nullable()->after('client_id')
                ->constrained('client_projects')->nullOnDelete();
            $table->boolean('hidden_from_owner')->default(false)->after('continued_from_project_id');
        });
    }

    public function down(): void
    {
        Schema::table('client_projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('continued_from_project_id');
            $table->dropColumn('hidden_from_owner');
        });
    }
};
