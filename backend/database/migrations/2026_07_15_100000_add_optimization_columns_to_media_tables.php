<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Media optimization pipeline (OptimizeMedia / OptimizeAttachment jobs).
 *
 * Photos are re-encoded to WebP and videos to 1080p-class H.264 MP4 — the
 * optimized file REPLACES the original bytes (owner decision 2026-07-09), and a
 * small derivative (photo thumbnail / video poster frame) is stored alongside
 * for fast gallery grids. `original_size_bytes` keeps the pre-optimization size
 * so savings stay auditable after the original is gone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            // pending | ready | failed | skipped (null = predates the pipeline
            // or a type the pipeline doesn't touch: pdf / office docs).
            $table->string('optimize_status')->nullable()->after('preview_status');
            // Grid derivative on the same disk: photo thumbnail or video poster.
            $table->string('thumb_path')->nullable()->after('optimize_status');
            $table->unsignedBigInteger('original_size_bytes')->nullable()->after('size_bytes');
            // Final (post-optimization) pixel dimensions / duration — lets the
            // frontend reserve layout space and show video length without probing.
            $table->unsignedInteger('width')->nullable()->after('original_size_bytes');
            $table->unsignedInteger('height')->nullable()->after('width');
            $table->unsignedInteger('duration_seconds')->nullable()->after('height');
        });

        Schema::table('message_attachments', function (Blueprint $table) {
            // Chat images get a single in-place WebP re-encode (no thumbnail).
            $table->string('optimize_status')->nullable()->after('size_bytes');
            $table->unsignedBigInteger('original_size_bytes')->nullable()->after('optimize_status');
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->dropColumn([
                'optimize_status', 'thumb_path', 'original_size_bytes',
                'width', 'height', 'duration_seconds',
            ]);
        });

        Schema::table('message_attachments', function (Blueprint $table) {
            $table->dropColumn(['optimize_status', 'original_size_bytes']);
        });
    }
};
