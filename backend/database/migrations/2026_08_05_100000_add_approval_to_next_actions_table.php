<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manager approval for beyond-window office-visit plans. An office visit is
 * expected within `office_visit_max_days` (app setting, default 1 = today or
 * tomorrow); a plan further out is created normally but flagged
 * approval_status=pending, and a visits.dispatch holder approves / denies /
 * reschedules it from the Office Visits Program page.
 *
 * approval_status  null = in-window (no approval involved) | pending |
 *                  approved | denied | rescheduled (superseded by the
 *                  manager's own corrected plan).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('next_actions', function (Blueprint $table) {
            $table->string('approval_status', 20)->nullable();
            $table->foreignId('approval_requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approval_decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approval_decided_at')->nullable();
            $table->string('approval_reason', 500)->nullable();

            $table->index('approval_status');
        });
    }

    public function down(): void
    {
        Schema::table('next_actions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approval_requested_by');
            $table->dropConstrainedForeignId('approval_decided_by');
            $table->dropIndex(['approval_status']);
            $table->dropColumn(['approval_status', 'approval_decided_at', 'approval_reason']);
        });
    }
};
