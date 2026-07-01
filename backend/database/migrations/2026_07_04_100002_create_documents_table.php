<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * documents = branded, generated PDFs (receipts, contracts, quotes, schedules).
 * Polymorphic so any record can own documents. Stored on a private disk; `number`
 * is unique/sequential; `meta` snapshots the rendered figures so a reprint is
 * faithful even if related records later change. See docs/database/04-payments.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->morphs('documentable'); // documentable_type + documentable_id (+ index)
            $table->string('type'); // receipt|contract|quote|schedule
            $table->string('number')->unique(); // e.g. REC-2026-000123
            $table->string('template'); // template key used to render
            $table->string('disk')->default('documents');
            $table->string('path')->nullable(); // filled once the queued render completes
            $table->string('render_status')->default('pending'); // pending|ready|failed
            $table->unsignedInteger('version')->default(1); // regeneration bumps version
            $table->foreignId('generated_by')->constrained('users');
            $table->timestamp('generated_at')->nullable();
            $table->json('meta')->nullable(); // snapshot of the figures rendered

            // Base-model columns (BaseModel: Cancellable + HasVersions).
            $table->string('status')->default('active');
            $table->string('cancellation_reason')->nullable();
            $table->foreignId('supersedes_id')->nullable()
                ->constrained('documents')->nullOnDelete();
            $table->timestamps();

            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
