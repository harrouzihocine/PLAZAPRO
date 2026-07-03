<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * shortlist_items = the properties (units and/or boxes) a client wants, captured at
 * the office visit. Each row carries the property's journey through the phases via
 * `state`: shortlisted → not_visited / visited_(not_)interested (site agent) →
 * won / lost (closure). The in-site visits and outcome logs are generated from
 * these rows. See docs/phase-3-clients-pipeline.md (redesign).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shortlist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_project_id')->constrained('client_projects');
            // The office visit where the property was shortlisted (informational).
            $table->foreignId('office_visit_id')->nullable()
                ->constrained('visits')->nullOnDelete();
            // Polymorphic: a unit (apartment / local) or a box.
            $table->string('shortlistable_type');
            $table->unsignedBigInteger('shortlistable_id');
            $table->string('state')->default('shortlisted');
            $table->text('note')->nullable();

            // Base-model columns (BaseModel: Cancellable + HasVersions).
            $table->string('status')->default('active');
            $table->string('cancellation_reason')->nullable();
            $table->foreignId('supersedes_id')->nullable()
                ->constrained('shortlist_items')->nullOnDelete();
            $table->timestamps();

            $table->index('client_project_id');
            $table->index(['shortlistable_type', 'shortlistable_id']);
            $table->index('state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shortlist_items');
    }
};
