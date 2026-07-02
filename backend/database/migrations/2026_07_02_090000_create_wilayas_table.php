<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Algeria's wilayas (provinces) — the top of the geographic hierarchy. Each
 * wilaya hasMany communes. Seeded from the official 58-wilaya division
 * (WilayaCommuneSeeder), and editable in-app via the Settings screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wilayas', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();   // official numeric code, "01".."58"
            $table->string('name');

            // Base-model columns (BaseModel: Cancellable + HasVersions).
            $table->string('status')->default('active');
            $table->string('cancellation_reason')->nullable();
            $table->foreignId('supersedes_id')->nullable()
                ->constrained('wilayas')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wilayas');
    }
};
