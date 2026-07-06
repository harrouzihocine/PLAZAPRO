<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Explicit project freeze — replaces the implicit "won ⇒ frozen" rule. A
 * frozen project takes no new activity (calls, plans, visits, deals, chat);
 * payments and documents still flow. Freezing/unfreezing is a deliberate,
 * permission-gated act (projects.freeze), stamped with who and when.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_projects', function (Blueprint $table) {
            $table->timestamp('frozen_at')->nullable()->after('closed_to_desire_at');
            $table->foreignId('frozen_by')->nullable()->after('frozen_at')
                ->constrained('users');
        });
    }

    public function down(): void
    {
        Schema::table('client_projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('frozen_by');
            $table->dropColumn('frozen_at');
        });
    }
};
