<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * idempotency_keys = the replay ledger for offline-queued writes. A client
 * sends X-Idempotency-Key (a UUID it minted when the user acted); the first
 * SUCCESSFUL response is stored and any replay of the same key returns that
 * stored response instead of re-executing — a flaky reconnect can never
 * double-post a call log or a chat message. Rows are pruned after 7 days
 * (routes/console.php); the client never replays older than that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->uuid('key');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('method', 8);
            $table->string('path');
            $table->unsignedSmallInteger('status_code');
            $table->longText('response_body')->nullable();
            $table->timestamp('created_at');

            $table->unique(['user_id', 'key']);
            $table->index('created_at'); // prune scan
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
