<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A done payment can be REFUNDED (money went back to the client) — distinct
 * from a correction (the record was wrong). The row is never cancelled: it
 * stays in history flagged refunded, its allocation reversed on the schedule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('versements', function (Blueprint $table) {
            $table->timestamp('refunded_at')->nullable()->after('document_id');
            $table->string('refund_reason', 500)->nullable()->after('refunded_at');
            $table->foreignId('refunded_by')->nullable()->after('refund_reason')
                ->constrained('users');
        });
    }

    public function down(): void
    {
        Schema::table('versements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('refunded_by');
            $table->dropColumn(['refunded_at', 'refund_reason']);
        });
    }
};
