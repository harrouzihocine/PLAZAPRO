<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * deals = the reservation-stage commitment on a client project: the properties the
 * client picked (only the ones they are interested in) go into ONE active deal and
 * are auto-reserved. A deal is born from a visit log (visit_id = provenance);
 * creating one directly needs the deals.direct permission. state: reserved →
 * won / lost. deal_items are the reserved properties (units + allocated boxes).
 *
 * client_projects.closed_to_desire_at marks a project closed back to the desire
 * list (archived + waiting for matching inventory); cleared on reactivation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_project_id')->constrained('client_projects');
            // The visit log the deal came from (null only for permitted direct deals).
            $table->foreignId('visit_id')->nullable()->constrained('visits')->nullOnDelete();
            $table->string('state')->default('reserved');
            $table->decimal('total_price', 12, 2)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // Base-model columns (BaseModel: Cancellable + HasVersions).
            $table->string('status')->default('active');
            $table->string('cancellation_reason')->nullable();
            $table->foreignId('supersedes_id')->nullable()->constrained('deals')->nullOnDelete();
            $table->timestamps();

            $table->index('client_project_id');
            $table->index('state');
        });

        Schema::create('deal_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->constrained('deals');
            // Exactly one of unit_id / box_id is set (a reserved apartment/local,
            // or a box allocated alongside it).
            $table->foreignId('unit_id')->nullable()->constrained('units');
            $table->foreignId('box_id')->nullable()->constrained('boxes');

            $table->string('status')->default('active');
            $table->string('cancellation_reason')->nullable();
            $table->foreignId('supersedes_id')->nullable()->constrained('deal_items')->nullOnDelete();
            $table->timestamps();

            $table->index('deal_id');
        });

        Schema::table('client_projects', function (Blueprint $table) {
            $table->timestamp('closed_to_desire_at')->nullable()->after('total_price');
        });
    }

    public function down(): void
    {
        Schema::table('client_projects', function (Blueprint $table) {
            $table->dropColumn('closed_to_desire_at');
        });
        Schema::dropIfExists('deal_items');
        Schema::dropIfExists('deals');
    }
};
