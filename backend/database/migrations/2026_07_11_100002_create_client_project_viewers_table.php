<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-project visibility list: the users who can see a project besides its
 * creator (and the roles holding projects.view_all). Rows are hidden
 * (hidden_at), never deleted, so the "who was given access" history is kept —
 * mirrors the app-wide no-delete rule. added_by records who granted access.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_project_viewers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_project_id')->constrained('client_projects')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('hidden_at')->nullable();
            $table->timestamps();

            $table->unique(['client_project_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_project_viewers');
    }
};
