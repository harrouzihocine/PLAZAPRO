<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A field agent's on-duty stretches: one open row (ended_at null) means the
 * agent is on duty now and their device shares its position; going off duty
 * closes the row. Plain telemetry — not a BaseModel business record — so rows
 * are never "cancelled", just closed. Location is only ever recorded while a
 * session is open: that is the privacy contract the whole GPS layer stands on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('duty_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('duty_sessions');
    }
};
