<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A project (location) may offer several payment / financing options to its
 * buyers, so the choice is a many-to-many onto the `project_payment_methods`
 * dynamic list (each row a `dynamic_list_items` id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('location_payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
            $table->foreignId('dynamic_list_item_id')->constrained('dynamic_list_items')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['location_id', 'dynamic_list_item_id'], 'location_payment_method_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_payment_methods');
    }
};
