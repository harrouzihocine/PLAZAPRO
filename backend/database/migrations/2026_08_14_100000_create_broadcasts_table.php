<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * broadcasts = human-authored notifications: a permitted user writes a message
 * (in any of en/fr/ar) and sends it to selected users, a whole role, or everyone.
 * Each send fans out into the normal per-recipient notifications table (the bell
 * feed + live badge + push); this table is the sender-side record backing the
 * company-wide history and its read tracking.
 *
 * broadcast_recipients snapshots exactly who a broadcast went to at send time —
 * so the audience survives even for `all`, and it is the join target for
 * "who has read it" (read_at is read live from each recipient's notification row).
 * See docs/database/05-collaboration.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('broadcasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            // Who it targeted: everyone, a whole role, or a hand-picked list.
            $table->enum('audience_type', ['all', 'role', 'users']);
            // Set only when audience_type = role.
            $table->foreignId('role_id')->nullable()->constrained('roles')->nullOnDelete();
            // The message per language ({en,fr,ar}); empty languages are dropped.
            // Each recipient reads their own locale, falling back to a filled one.
            $table->json('body_translations');
            // Denormalised recipient count at send time (the pivot is the source
            // of truth, but this keeps the history list cheap).
            $table->unsignedInteger('recipient_count')->default(0);
            $table->timestamps();

            $table->index('created_at');
        });

        Schema::create('broadcast_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('broadcast_id')->constrained('broadcasts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['broadcast_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('broadcast_recipients');
        Schema::dropIfExists('broadcasts');
    }
};
