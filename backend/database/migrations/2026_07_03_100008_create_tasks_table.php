<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * tasks = general to-dos assigned to a user, optionally about a client/unit/deal.
 * The dedicated tasks page is built in Phase 5; Phase 3 lays the table + model so
 * reminders can reference tasks. See docs/database/03-clients-pipeline.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('assigned_to')->constrained('users');
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->string('priority')->default('normal'); // low | normal | high
            $table->string('state')->default('open'); // open | done | cancelled

            // Base-model columns (BaseModel: Cancellable + HasVersions).
            $table->string('status')->default('active');
            $table->string('cancellation_reason')->nullable();
            $table->foreignId('supersedes_id')->nullable()
                ->constrained('tasks')->nullOnDelete();
            $table->timestamps();

            $table->index(['assigned_to', 'state', 'due_at']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
