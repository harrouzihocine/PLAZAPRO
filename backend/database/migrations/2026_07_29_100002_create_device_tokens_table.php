<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * device_tokens = FCM registration tokens for the Android shell's system-tray
 * push notifications. One row per device; the token is globally unique, so a
 * login by another user on the same device re-assigns the row (the previous
 * user must stop receiving that device's pushes). Infrastructure, not domain
 * data — rows are hard-deleted when FCM reports them dead or on logout.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('token', 512)->unique();
            $table->string('platform', 20)->default('android');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
    }
};
