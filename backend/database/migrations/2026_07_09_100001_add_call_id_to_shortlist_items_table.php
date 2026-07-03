<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Properties can now be shortlisted during a CALL (Phase-2 qualification), not
 * only at the office visit — `call_id` records that provenance, parallel to the
 * existing nullable `office_visit_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shortlist_items', function (Blueprint $table) {
            $table->foreignId('call_id')->nullable()->after('office_visit_id')
                ->constrained('calls')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shortlist_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('call_id');
        });
    }
};
