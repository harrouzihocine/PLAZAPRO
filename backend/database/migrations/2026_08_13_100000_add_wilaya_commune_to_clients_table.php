<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Capture where a client is from: an optional wilaya + commune of residence,
 * the same Algeria admin hierarchy that backs projects and desires. Both are
 * nullable — a lead is often just a phone — and null on their reference's
 * removal, mirroring locations.wilaya_id / commune_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->foreignId('wilaya_id')->nullable()->after('address')
                ->constrained('wilayas')->nullOnDelete();
            $table->foreignId('commune_id')->nullable()->after('wilaya_id')
                ->constrained('communes')->nullOnDelete();

            $table->index('wilaya_id');
            $table->index('commune_id');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('wilaya_id');
            $table->dropConstrainedForeignId('commune_id');
        });
    }
};
