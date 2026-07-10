<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tasks board rework: CRM categories (follow-up, prospecting, content,
 * publication, training, admin), repeat-on-complete recurrence ("publish every
 * 12h" respawns when done) and a structured completion report — finishing a
 * task now records what was done, the outcome, difficulties and time spent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('category')->default('follow_up')->after('title');
            // Repeat-on-complete: when done, a fresh occurrence is opened due
            // this many hours later. Null = one-off task.
            $table->unsignedSmallInteger('repeat_every_hours')->nullable()->after('priority');
            $table->foreignId('created_by')->nullable()->after('assigned_to')
                ->constrained('users')->nullOnDelete();

            // Completion report (required to mark a task done).
            $table->timestamp('completed_at')->nullable()->after('state');
            $table->foreignId('completed_by')->nullable()->after('completed_at')
                ->constrained('users')->nullOnDelete();
            $table->string('completion_outcome')->nullable()->after('completed_by'); // full | partial | issues
            $table->text('completion_summary')->nullable()->after('completion_outcome');
            $table->text('completion_difficulties')->nullable()->after('completion_summary');
            $table->unsignedSmallInteger('time_spent_minutes')->nullable()->after('completion_difficulties');

            $table->index(['category', 'state']);
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['category', 'state']);
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('completed_by');
            $table->dropColumn([
                'category', 'repeat_every_hours', 'completed_at',
                'completion_outcome', 'completion_summary',
                'completion_difficulties', 'time_spent_minutes',
            ]);
        });
    }
};
