<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records which user created each client. Set automatically on create and shown
 * only to back-office roles (super-admin / admin / manager). nullOnDelete keeps
 * clients intact if the creating user is later removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->after('assigned_agent_id')
                ->constrained('users')->nullOnDelete();
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
        });
    }
};
