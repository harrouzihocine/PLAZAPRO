<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * message_attachments = images, voice notes and files on a message. Stored on a
 * private disk with UUID names (same hardening as inventory media); served only
 * through a participant-gated streaming endpoint. See docs/database/05-collaboration.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('messages')->cascadeOnDelete();
            $table->string('kind'); // image | voice | file
            $table->string('disk');
            $table->string('path');
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('duration_ms')->nullable(); // voice-note length
            $table->unsignedInteger('width')->nullable();        // image dimensions
            $table->unsignedInteger('height')->nullable();
            $table->json('meta')->nullable(); // waveform, thumbnail ref, …

            // Base-model columns (BaseModel: Cancellable + HasVersions).
            $table->string('status')->default('active');
            $table->string('cancellation_reason')->nullable();
            $table->foreignId('supersedes_id')->nullable()
                ->constrained('message_attachments')->nullOnDelete();
            $table->timestamps();

            $table->index('message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_attachments');
    }
};
