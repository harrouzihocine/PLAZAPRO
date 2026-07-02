<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Communes (municipalities) — the child of a wilaya. `wilaya_id` points at the
 * parent wilaya; `daira_name` keeps the district label from the official
 * division for reference. Seeded by WilayaCommuneSeeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wilaya_id')->constrained('wilayas');
            $table->string('name');
            $table->string('daira_name')->nullable();

            // Base-model columns (BaseModel: Cancellable + HasVersions).
            $table->string('status')->default('active');
            $table->string('cancellation_reason')->nullable();
            $table->foreignId('supersedes_id')->nullable()
                ->constrained('communes')->nullOnDelete();
            $table->timestamps();

            $table->index('wilaya_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communes');
    }
};
