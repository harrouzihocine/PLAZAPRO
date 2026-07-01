<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * next_actions = the enforced next step. Every completed call/visit must leave one,
 * and a client/project should always have exactly one open `pending` action so the
 * pipeline never goes cold. `subject` = the client/project it belongs to; `source`
 * = the call/visit that created it. See docs/database/03-clients-pipeline.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('next_actions', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('type'); // call|office_visit|apartment_visit|follow_up|send_docs
            $table->timestamp('due_at');
            $table->foreignId('assigned_to')->constrained('users');
            $table->string('state')->default('pending'); // pending | done | cancelled
            $table->timestamp('completed_at')->nullable();

            // Base-model columns (BaseModel: Cancellable + HasVersions).
            $table->string('status')->default('active');
            $table->string('cancellation_reason')->nullable();
            $table->foreignId('supersedes_id')->nullable()
                ->constrained('next_actions')->nullOnDelete();
            $table->timestamps();

            $table->index(['state', 'due_at']); // the reminder sweep queries this
            $table->index(['subject_type', 'subject_id']);
            $table->index('assigned_to');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('next_actions');
    }
};
