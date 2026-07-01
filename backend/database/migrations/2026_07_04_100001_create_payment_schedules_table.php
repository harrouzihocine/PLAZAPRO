<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * payment_schedules = the instalment plan for a deal (client_project). Each row
 * is one instalment (1..N). `state` and `paid_amount` are DERIVED server-side by
 * the AllocateVersement Action / overdue sweep — never client-set. See
 * docs/database/04-payments.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_project_id')->constrained('client_projects');
            $table->unsignedInteger('installment_no'); // 1..N
            $table->date('due_date');
            $table->decimal('amount', 12, 2); // planned amount
            $table->string('state')->default('pending'); // pending|paid|partial|overdue|cancelled
            $table->decimal('paid_amount', 12, 2)->default(0); // sum of allocated versements

            // Base-model columns (BaseModel: Cancellable + HasVersions).
            $table->string('status')->default('active');
            $table->string('cancellation_reason')->nullable();
            $table->foreignId('supersedes_id')->nullable()
                ->constrained('payment_schedules')->nullOnDelete();
            $table->timestamps();

            $table->index(['client_project_id', 'installment_no']);
            $table->index(['state', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_schedules');
    }
};
