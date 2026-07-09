<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Units go dual-price: the historical `price` column was always the SEMI-FINI
 * (semi-finished) price — renamed to say so — and units gain an optional FINI
 * (finished / turnkey) price. A unit carries either one or both (at least one,
 * DB-enforced). When both exist the client picks the finish; that choice lives
 * on the shortlist item (proposal) and the deal item (commitment) as
 * `finish_type` (semi_fini | fini). See App\Modules\Inventory\Enums\FinishType
 * and docs/database/02-inventory.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->renameColumn('price', 'price_semi_fini');
        });

        Schema::table('units', function (Blueprint $table) {
            $table->decimal('price_semi_fini', 12, 2)->nullable()->change();
            $table->decimal('price_fini', 12, 2)->nullable()->after('price_semi_fini');
        });

        // A unit must be sellable at SOMETHING: at least one of the two prices.
        DB::statement(
            'ALTER TABLE units ADD CONSTRAINT chk_units_one_price'
            .' CHECK (price_semi_fini IS NOT NULL OR price_fini IS NOT NULL)',
        );

        Schema::table('shortlist_items', function (Blueprint $table) {
            // The finish proposed to the client (semi_fini | fini); null on boxes.
            $table->string('finish_type')->nullable()->after('note');
        });

        Schema::table('deal_items', function (Blueprint $table) {
            // The finish the client committed to — drives the win-price prefill;
            // null on box items (boxes have no finishing level).
            $table->string('finish_type')->nullable()->after('agreed_price');
        });
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE units DROP CONSTRAINT chk_units_one_price');

        // Best effort: a fini-only unit keeps its fini price as `price`.
        DB::statement('UPDATE units SET price_semi_fini = COALESCE(price_semi_fini, price_fini)');

        Schema::table('units', function (Blueprint $table) {
            $table->decimal('price_semi_fini', 12, 2)->nullable(false)->change();
            $table->dropColumn('price_fini');
        });

        Schema::table('units', function (Blueprint $table) {
            $table->renameColumn('price_semi_fini', 'price');
        });

        Schema::table('shortlist_items', fn (Blueprint $table) => $table->dropColumn('finish_type'));
        Schema::table('deal_items', fn (Blueprint $table) => $table->dropColumn('finish_type'));
    }
};
