<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * notifications = Laravel's own notification store (uuid PK, morph notifiable,
 * json payload, read_at). These are transient UX records, NOT audited domain
 * data, so they use the framework table rather than BaseModel. Domain actions
 * that matter are still captured in activity_log.
 * See docs/database/05-collaboration.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable'); // notifiable_type + notifiable_id (+ index)
            $table->json('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            // Fast "unread for this user" lookups (the badge + inbox query).
            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
