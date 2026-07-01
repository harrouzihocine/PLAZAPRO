<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * reservations encode the 48-hour hold rule. `client_project_id` is nullable and
 * carries NO foreign key yet — the `client_projects` table arrives in Phase 3,
 * which adds the constrained FK. See docs/database/02-inventory.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->constrained('units');
            $table->unsignedBigInteger('client_project_id')->nullable(); // FK added in Phase 3
            $table->foreignId('held_by')->constrained('users');
            $table->timestamp('held_at');
            $table->timestamp('expires_at');
            $table->string('hold_status')->default('active'); // active | expired | converted | released

            // Base-model columns (BaseModel: Cancellable + HasVersions).
            $table->string('status')->default('active');
            $table->string('cancellation_reason')->nullable();
            $table->foreignId('supersedes_id')->nullable()
                ->constrained('reservations')->nullOnDelete();
            $table->timestamps();

            $table->index('unit_id');
            $table->index(['hold_status', 'expires_at']); // the expiry sweeper queries this
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
