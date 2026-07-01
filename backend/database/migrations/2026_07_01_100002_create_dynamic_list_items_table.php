<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An item within a dynamic list. Other tables reference `id` (e.g.
 * clients.source_id), so adding an option needs no migration. `value` is the
 * stable machine value; `is_active` hides an option without deleting it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dynamic_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dynamic_list_id')->constrained('dynamic_lists')->cascadeOnDelete();
            $table->string('label');                       // shown in the dropdown
            $table->string('value');                       // stable machine value (referenced elsewhere)
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('parent_id')->nullable()->constrained('dynamic_list_items')->nullOnDelete();
            $table->boolean('is_active')->default(true);   // hide without deleting
            $table->json('meta')->nullable();              // optional extra: colour, weight, ...
            // Base-model columns:
            $table->string('status')->default('active');
            $table->string('cancellation_reason')->nullable();
            $table->foreignId('supersedes_id')->nullable()->constrained('dynamic_list_items')->nullOnDelete();
            $table->timestamps();

            $table->unique(['dynamic_list_id', 'value']);  // value is unambiguous within its list
            $table->index(['dynamic_list_id', 'sort_order']);
            $table->index(['dynamic_list_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dynamic_list_items');
    }
};
