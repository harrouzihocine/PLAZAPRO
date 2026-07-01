<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * visits = office or apartment visits. apartment visits require a unit; the agent
 * must be a user whose role is_agent (enforced in AssignVisit + the FormRequests).
 * Completing a visit leaves a next action. See docs/database/03-clients-pipeline.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients');
            $table->foreignId('client_project_id')->nullable()
                ->constrained('client_projects')->nullOnDelete();
            $table->string('type'); // office | apartment
            $table->foreignId('unit_id')->nullable()
                ->constrained('units')->nullOnDelete();
            $table->foreignId('agent_id')->constrained('users');
            $table->timestamp('scheduled_at');
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('outcome_id')->nullable()
                ->constrained('dynamic_list_items')->nullOnDelete();
            $table->text('notes')->nullable();

            // Base-model columns (BaseModel: Cancellable + HasVersions).
            $table->string('status')->default('active');
            $table->string('cancellation_reason')->nullable();
            $table->foreignId('supersedes_id')->nullable()
                ->constrained('visits')->nullOnDelete();
            $table->timestamps();

            $table->index(['agent_id', 'scheduled_at']);
            $table->index('client_id');
            $table->index('type');
            $table->index('unit_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visits');
    }
};
