<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * client_projects = a deal / opportunity a client is pursuing (a client may have
 * several). Parent of reservations, desire, and — later — payments and documents.
 * See docs/database/03-clients-pipeline.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients');
            $table->foreignId('location_id')->nullable()
                ->constrained('locations')->nullOnDelete();
            $table->foreignId('unit_id')->nullable()
                ->constrained('units')->nullOnDelete();
            $table->string('stage')->default('lead'); // lead|negotiating|reserved|won|lost
            $table->decimal('total_price', 12, 2)->nullable();

            // Base-model columns (BaseModel: Cancellable + HasVersions).
            $table->string('status')->default('active');
            $table->string('cancellation_reason')->nullable();
            $table->foreignId('supersedes_id')->nullable()
                ->constrained('client_projects')->nullOnDelete();
            $table->timestamps();

            $table->index('client_id');
            $table->index('unit_id');
            $table->index(['status', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_projects');
    }
};
