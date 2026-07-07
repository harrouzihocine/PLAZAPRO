<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Messenger-style "delete conversation": per-participant, direct/group threads
 * only (project chats follow the project). Deleting sets BOTH stamps on the
 * caller's own pivot row:
 *   hidden_at  — the thread leaves MY inbox; any new message clears it back to
 *                null for everyone (the thread resurrects, like Messenger).
 *   cleared_at — messages at or before this instant stay hidden from ME forever,
 *                even after the thread resurrects (history is gone for me only).
 * Nothing is deleted for the other participants — zero-deletion holds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_user', function (Blueprint $table) {
            $table->timestamp('hidden_at')->nullable()->after('muted');
            $table->timestamp('cleared_at')->nullable()->after('hidden_at');
        });
    }

    public function down(): void
    {
        Schema::table('conversation_user', function (Blueprint $table) {
            $table->dropColumn(['hidden_at', 'cleared_at']);
        });
    }
};
