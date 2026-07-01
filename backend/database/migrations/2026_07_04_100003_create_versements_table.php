<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * versements = recorded instalment payments — the guide's canonical no-delete
 * example. A recorded versement is NEVER edited or deleted: corrections go
 * through cancel-and-duplicate (HasVersions::supersedeWith → supersedes_id).
 * Money is decimal(12,2); arithmetic uses bcmath. See docs/database/04-payments.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('versements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_project_id')->constrained('client_projects');
            $table->decimal('amount', 12, 2);
            $table->date('paid_on');
            $table->foreignId('method_id')->constrained('dynamic_list_items'); // payment_methods
            $table->string('reference')->nullable(); // cheque no. / transfer ref
            $table->foreignId('schedule_item_id')->nullable()
                ->constrained('payment_schedules')->nullOnDelete(); // instalment it settles
            $table->foreignId('recorded_by')->constrained('users');
            $table->foreignId('document_id')->nullable()
                ->constrained('documents')->nullOnDelete(); // generated receipt

            // Base-model columns (BaseModel: Cancellable + HasVersions).
            $table->string('status')->default('active');
            $table->string('cancellation_reason')->nullable();
            $table->foreignId('supersedes_id')->nullable()
                ->constrained('versements')->nullOnDelete(); // cancel-and-duplicate correction
            $table->timestamps();

            $table->index(['client_project_id', 'status']);
            $table->index('method_id');
            $table->index('paid_on');
            $table->index('supersedes_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('versements');
    }
};
