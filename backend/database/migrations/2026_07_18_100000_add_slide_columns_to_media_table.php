<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Presentation mode for PPTX media (MakeMediaPreview job).
 *
 * Besides the inline PDF preview, each slide of a presentation is rasterized
 * to its own WebP (SlideShare-style) so the frontend can page through a deck
 * fullscreen like PowerPoint. `slides_path` is the directory prefix holding
 * `0001.webp`, `0002.webp`, … on the media disk; `slide_count` is how many.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->string('slides_path')->nullable()->after('preview_status');
            $table->unsignedSmallInteger('slide_count')->nullable()->after('slides_path');
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->dropColumn(['slides_path', 'slide_count']);
        });
    }
};
