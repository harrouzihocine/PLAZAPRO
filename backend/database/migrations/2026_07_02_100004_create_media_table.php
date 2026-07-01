<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Polymorphic, versioned media (photo/video/pdf/pptx). Attaches to any model
 * (locations, units, ...). Files live on a private disk with randomised names
 * and are never hard-deleted: replacing a file inserts a new version and cancels
 * the old row. PPTX gets a derived PDF (`preview_path`) for inline viewing.
 * See docs/database/02-inventory.md and phase-0-foundations/10-security-baseline.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->morphs('mediable'); // mediable_type + mediable_id (+ index)
            $table->string('collection')->nullable(); // gallery | brochure | floorplan
            $table->string('type'); // photo | video | pdf | pptx
            $table->string('disk')->default('media');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes');
            $table->string('cdn_url')->nullable();

            // Inline-preview pipeline (PPTX -> PDF via a queued LibreOffice job).
            $table->string('preview_path')->nullable();
            $table->string('preview_status')->nullable(); // pending | ready | failed

            $table->unsignedInteger('version')->default(1);
            $table->integer('sort_order')->default(0);
            $table->foreignId('uploaded_by')->constrained('users');

            // Base-model columns (BaseModel: Cancellable + HasVersions).
            $table->string('status')->default('active');
            $table->string('cancellation_reason')->nullable();
            $table->foreignId('supersedes_id')->nullable()
                ->constrained('media')->nullOnDelete();
            $table->timestamps();

            $table->index(['mediable_type', 'mediable_id', 'collection', 'sort_order'], 'media_gallery_idx');
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
