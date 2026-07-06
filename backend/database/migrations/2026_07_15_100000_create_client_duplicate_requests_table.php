<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A duplicate-phone create attempt that a supervisor must resolve. When a user
 * tries to add a client whose phone already belongs to someone else's client,
 * creation is blocked and this record is opened: the resolver (clients.duplicates
 * .resolve) then denies it or shares a project — with or without client details —
 * so no user can silently take another user's client.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_duplicate_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('existing_client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            // What the finder tried to enter (so the resolver has context) — never
            // used to create a client; the existing one is the source of truth.
            $table->json('attempted_data')->nullable();
            $table->string('status')->default('pending'); // pending | shared | denied
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('resolution')->nullable(); // deny | share_project
            $table->foreignId('shared_project_id')->nullable()
                ->constrained('client_projects')->nullOnDelete();
            $table->boolean('share_details')->default(false);
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'existing_client_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_duplicate_requests');
    }
};
