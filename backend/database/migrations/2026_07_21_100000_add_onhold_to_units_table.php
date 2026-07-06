<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "On hold" — a new, stronger sale state layered above `reserved`.
 *
 * Reservation becomes multi-project (a unit can be reserved by several client
 * projects as backups — the "Reserved N" counter derives from active holds).
 * On hold is the hard, single-holder off-market lock a client earns by paying a
 * holding deposit: nobody else can BUY it (others may still queue as reserved
 * backups) until it is sold or the hold lapses. The lock carries its own expiry
 * (`onhold_expires_at`, swept by onhold:expire) and remembers the holder
 * (`onhold_project_id`) so the "only the holder may buy/deposit" guards are O(1).
 *
 * sale_status precedence is now: sold > onhold > reserved > available.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table) {
            // When the deposit hold lapses without a sale (swept to available /
            // reserved). Null = not on hold.
            $table->timestamp('onhold_expires_at')->nullable()->after('sale_status');
            // The single project that took the unit off the market with a deposit.
            $table->foreignId('onhold_project_id')->nullable()->after('onhold_expires_at')
                ->constrained('client_projects')->nullOnDelete();
            // The expiry sweeper scans (sale_status = onhold, onhold_expires_at <= now).
            $table->index(['sale_status', 'onhold_expires_at']);
        });

        // The holding-deposit window (hours), sibling of reservation_hold_hours.
        DB::table('app_settings')->insertOrIgnore([
            'key' => 'onhold_hold_hours',
            'value' => '72',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('app_settings')->where('key', 'onhold_hold_hours')->delete();

        Schema::table('units', function (Blueprint $table) {
            $table->dropIndex('units_sale_status_onhold_expires_at_index');
            $table->dropConstrainedForeignId('onhold_project_id');
            $table->dropColumn('onhold_expires_at');
        });
    }
};
