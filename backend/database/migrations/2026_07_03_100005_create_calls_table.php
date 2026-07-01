<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * calls = logged phone interactions with a client. Logging a call always leaves a
 * next action (enforced in the Action + FormRequest). See docs/database/03-clients-pipeline.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients');
            $table->foreignId('client_project_id')->nullable()
                ->constrained('client_projects')->nullOnDelete();
            $table->foreignId('agent_id')->constrained('users');
            $table->string('direction'); // inbound | outbound
            $table->foreignId('outcome_id')->nullable()
                ->constrained('dynamic_list_items')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('called_at');

            // Base-model columns (BaseModel: Cancellable + HasVersions).
            $table->string('status')->default('active');
            $table->string('cancellation_reason')->nullable();
            $table->foreignId('supersedes_id')->nullable()
                ->constrained('calls')->nullOnDelete();
            $table->timestamps();

            $table->index('client_id');
            $table->index(['client_id', 'called_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calls');
    }
};
