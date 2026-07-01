<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * locations = real-estate projects / buildings / sites (they hold units and
 * boxes). The geographic dropdown is the `areas` dynamic list, not this table.
 * See docs/database/02-inventory.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->foreignId('area_id')->nullable()
                ->constrained('dynamic_list_items')->nullOnDelete();
            $table->string('address')->nullable();
            $table->text('description')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            // Base-model columns (BaseModel: Cancellable + HasVersions).
            $table->string('status')->default('active');
            $table->string('cancellation_reason')->nullable();
            $table->foreignId('supersedes_id')->nullable()
                ->constrained('locations')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
            $table->index('area_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
