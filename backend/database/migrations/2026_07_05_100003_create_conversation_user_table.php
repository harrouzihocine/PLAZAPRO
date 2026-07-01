<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * conversation_user = participants + per-participant visibility state (unread
 * cursor, mute). Who can see a conversation is defined by these rows. Plain
 * pivot (composite PK), not a BaseModel. See docs/database/05-collaboration.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_user', function (Blueprint $table) {
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role')->default('member'); // member | admin (group admin)
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('last_read_at')->nullable(); // drives the unread badge
            $table->boolean('muted')->default(false);

            $table->primary(['conversation_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_user');
    }
};
