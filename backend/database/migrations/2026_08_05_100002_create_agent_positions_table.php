<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * On-duty GPS breadcrumbs, insert-only. Same 7-decimal precision as
 * `locations` (≈1 cm — plenty). Powers the dispatcher's live map (latest row
 * per agent), geofence arrival/departure stamping, ETA estimates and the
 * per-day replay. The scheduler prunes rows older than the configurable
 * retention window (agent_position_retention_days, default 30) — breadcrumbs
 * are operational telemetry, not an archive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedSmallInteger('accuracy_m')->nullable();
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->index(['user_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_positions');
    }
};
