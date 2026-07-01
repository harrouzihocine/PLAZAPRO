<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * reminders = auto-generated nudges for due/overdue next actions (and, later,
 * tasks). A scheduled sweep generates them from due next_actions; a second
 * scheduled job dispatches the pending ones. See docs/database/03-clients-pipeline.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('next_action_id')->nullable()
                ->constrained('next_actions')->nullOnDelete();
            $table->foreignId('task_id')->nullable()
                ->constrained('tasks')->nullOnDelete();
            $table->timestamp('remind_at');
            $table->string('channel')->default('in_app'); // in_app | email
            $table->timestamp('sent_at')->nullable();
            $table->string('state')->default('pending'); // pending | sent | cancelled

            // Base-model columns (BaseModel: Cancellable + HasVersions).
            $table->string('status')->default('active');
            $table->string('cancellation_reason')->nullable();
            $table->foreignId('supersedes_id')->nullable()
                ->constrained('reminders')->nullOnDelete();
            $table->timestamps();

            $table->index(['state', 'remind_at']); // the dispatcher queries this
            $table->index('next_action_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminders');
    }
};
