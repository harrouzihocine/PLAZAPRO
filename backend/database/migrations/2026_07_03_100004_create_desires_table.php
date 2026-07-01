<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * desires = what a client is looking for, matched against available inventory by
 * the MatchDesireToInventory action (area / type / budget / rooms). Area and type
 * come from the dynamic lists (`areas`, `unit_types`).
 * See docs/database/03-clients-pipeline.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('desires', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients');
            $table->foreignId('client_project_id')->nullable()
                ->constrained('client_projects')->nullOnDelete();
            $table->foreignId('area_id')->nullable()
                ->constrained('dynamic_list_items')->nullOnDelete();
            $table->foreignId('type_id')->nullable()
                ->constrained('dynamic_list_items')->nullOnDelete();
            $table->string('floor_pref')->nullable();
            $table->unsignedTinyInteger('rooms_min')->nullable();
            $table->decimal('budget_min', 12, 2)->nullable();
            $table->decimal('budget_max', 12, 2)->nullable();
            $table->text('notes')->nullable();

            // Base-model columns (BaseModel: Cancellable + HasVersions).
            $table->string('status')->default('active');
            $table->string('cancellation_reason')->nullable();
            $table->foreignId('supersedes_id')->nullable()
                ->constrained('desires')->nullOnDelete();
            $table->timestamps();

            $table->index('client_id');
            $table->index('client_project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('desires');
    }
};
