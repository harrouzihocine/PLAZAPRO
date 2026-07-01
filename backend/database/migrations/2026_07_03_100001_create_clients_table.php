<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * clients = the people the agency sells to. Lead source and rating come from the
 * dynamic lists (`sources`, `client_ratings`); the assigned agent must be a user
 * whose role carries is_agent (enforced at the validation/Action layer, not the
 * DB). Clients are cancelled, never deleted. See docs/database/03-clients-pipeline.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('phone');
            $table->string('email')->nullable();
            $table->foreignId('source_id')->nullable()
                ->constrained('dynamic_list_items')->nullOnDelete();
            $table->foreignId('rating_id')->nullable()
                ->constrained('dynamic_list_items')->nullOnDelete();
            $table->foreignId('assigned_agent_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();

            // Base-model columns (BaseModel: Cancellable + HasVersions).
            $table->string('status')->default('active');
            $table->string('cancellation_reason')->nullable();
            $table->foreignId('supersedes_id')->nullable()
                ->constrained('clients')->nullOnDelete();
            $table->timestamps();

            $table->index('phone');
            $table->index('assigned_agent_id');
            $table->index('source_id');
            $table->index(['status', 'rating_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
