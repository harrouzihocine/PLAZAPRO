<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A project-level "parked off the market" veil. When `is_available` is false the
 * promoteur has withdrawn the whole project from selling: it disappears from
 * every internal selector (property pickers, cross-project sweeps, desire
 * matching) and shows greyed on the public site — but stays in the inventory
 * management list (tagged) and keeps every unit's own sale_status intact, so
 * flipping it back on restores the project exactly as it was.
 *
 * Distinct from the record lifecycle `status`: `archived` hides a project from
 * management too, `cancelled` removes it. Default true — existing projects are
 * for sale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->boolean('is_available')->default(true)->after('is_published');
            $table->index('is_available');
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropIndex(['is_available']);
            $table->dropColumn('is_available');
        });
    }
};
