<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks when the creator was reminded that a client is still empty (no project,
 * no call, no desire). Lets the FlagEmptyClients sweep nudge once, not daily.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->timestamp('empty_reminded_at')->nullable()->after('address');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('empty_reminded_at');
        });
    }
};
