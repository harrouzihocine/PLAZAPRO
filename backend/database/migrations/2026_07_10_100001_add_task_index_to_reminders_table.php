<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The task-reminder sweep (GenerateDueReminders::forTasks) filters reminders by
 * task_id + state on every hourly run; mirror the index the next-action sweep
 * already has on next_action_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reminders', function (Blueprint $table) {
            $table->index(['task_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::table('reminders', function (Blueprint $table) {
            $table->dropIndex(['task_id', 'state']);
        });
    }
};
