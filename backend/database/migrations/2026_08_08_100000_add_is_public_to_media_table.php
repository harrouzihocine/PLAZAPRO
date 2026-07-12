<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-asset publish switch for the public showcase. Defaults to true so
 * everything already visible stays visible; owners untick the shots they
 * want to keep internal (PublicMediaGate + the public queries honour it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->boolean('is_public')->default(true)->after('sort_order');
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->dropColumn('is_public');
        });
    }
};
