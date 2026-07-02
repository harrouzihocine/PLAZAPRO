<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * units = apartments / lots inside a location. Uses HasVersions: price/status
 * corrections cancel the original and insert a linked replacement, so several
 * rows can share a `reference` over time. A DB-level unique on (location_id,
 * reference) would therefore reject the replacement, so uniqueness of the
 * reference *among live (active) units* is enforced at the validation layer
 * (StoreUnitRequest/UpdateUnitRequest) and the column carries a plain composite
 * index for fast lookups. See docs/database/02-inventory.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained('locations')->cascadeOnUpdate();
            $table->string('reference');
            $table->foreignId('type_id')->nullable()
                ->constrained('dynamic_list_items')->nullOnDelete();
            $table->foreignId('floor_id')->nullable()
                ->constrained('dynamic_list_items')->nullOnDelete();
            $table->decimal('area_sqm', 8, 2)->nullable();
            $table->decimal('price', 12, 2);
            $table->string('sale_status')->default('available'); // available | reserved | sold

            // Visual stacking-plan coordinates.
            $table->string('block')->nullable();
            $table->integer('stack_floor')->nullable();
            $table->integer('position')->nullable();

            // Go-to-market / sales priority — which units the vente team pushes
            // first (see App\Modules\Inventory\Enums\GtmPriority).
            $table->string('gtm_priority')->default('medium');

            // Base-model columns (BaseModel: Cancellable + HasVersions).
            $table->string('status')->default('active');
            $table->string('cancellation_reason')->nullable();
            $table->foreignId('supersedes_id')->nullable()
                ->constrained('units')->nullOnDelete();
            $table->timestamps();

            $table->index('location_id');
            $table->index(['location_id', 'reference']);
            $table->index(['sale_status', 'status']);
            $table->index('gtm_priority');
            $table->index(['block', 'stack_floor', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};
