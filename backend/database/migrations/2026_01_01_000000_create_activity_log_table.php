<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The append-only activity log (guide Table 4.1). Written automatically by the
 * base model; never updated or deleted. Note: no updated_at column.
 *
 * Runs after Laravel's default users migration (0001_01_01_*) so the FK resolves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('role_at_time')->nullable();
            $table->string('action')->index(); // create/update/cancel/restore/duplicate/view/login/export
            $table->nullableMorphs('subject');  // subject_type + subject_id (+ composite index)
            $table->json('changes')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent(); // append-only: no updated_at

            $table->index(['user_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
    }
};
