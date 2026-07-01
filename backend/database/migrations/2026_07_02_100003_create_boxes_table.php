<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * boxes = parking / storage lots inside a location. Optionally linked to a unit
 * (a box sold together with an apartment). See docs/database/02-inventory.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained('locations')->cascadeOnUpdate();
            $table->string('reference');
            $table->foreignId('type_id')->nullable()
                ->constrained('dynamic_list_items')->nullOnDelete();
            $table->decimal('price', 12, 2);
            $table->string('sale_status')->default('available'); // available | reserved | sold
            $table->foreignId('unit_id')->nullable()
                ->constrained('units')->nullOnDelete();

            // Base-model columns (BaseModel: Cancellable + HasVersions).
            $table->string('status')->default('active');
            $table->string('cancellation_reason')->nullable();
            $table->foreignId('supersedes_id')->nullable()
                ->constrained('boxes')->nullOnDelete();
            $table->timestamps();

            $table->index('location_id');
            $table->index(['location_id', 'reference']);
            $table->index(['sale_status', 'status']);
            $table->index('unit_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boxes');
    }
};
