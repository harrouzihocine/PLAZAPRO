<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * locations = real-estate projects / buildings / sites (they hold units and
 * boxes). The geographic dropdowns are the `wilayas`/`communes` tables.
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
            $table->foreignId('wilaya_id')->nullable()
                ->constrained('wilayas')->nullOnDelete();
            $table->foreignId('commune_id')->nullable()
                ->constrained('communes')->nullOnDelete();
            // Sale contract the project is marketed under (dynamic list
            // `contract_types`); surfaced on the project and its units.
            $table->foreignId('contract_type_id')->nullable()
                ->constrained('dynamic_list_items')->nullOnDelete();
            $table->string('address')->nullable();
            $table->text('description')->nullable();
            // Estimated hand-over / readiness date for the project (delivery).
            $table->date('expected_delivery_date')->nullable();
            // Go-to-market / sales priority — drives how the vente team ranks
            // projects to push (see App\Modules\Inventory\Enums\GtmPriority).
            $table->string('gtm_priority')->default('medium');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            // Base-model columns (BaseModel: Cancellable + HasVersions).
            $table->string('status')->default('active');
            $table->string('cancellation_reason')->nullable();
            $table->foreignId('supersedes_id')->nullable()
                ->constrained('locations')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
            $table->index('gtm_priority');
            $table->index('wilaya_id');
            $table->index('commune_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
