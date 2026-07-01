<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The reusable dropdown backbone: a named list (e.g. payment_methods) keyed by a
 * stable machine `key` that application code and every dropdown reference.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dynamic_lists', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();               // machine key: payment_methods, areas, ...
            $table->string('name');                        // human label for the admin UI
            $table->string('description')->nullable();
            $table->boolean('is_system')->default(false);  // system lists can't be renamed/removed
            // Base-model columns:
            $table->string('status')->default('active');
            $table->string('cancellation_reason')->nullable();
            $table->foreignId('supersedes_id')->nullable()->constrained('dynamic_lists')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dynamic_lists');
    }
};
