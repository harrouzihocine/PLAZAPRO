<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-unit payment options + a free-text note.
 *
 *  - A unit normally INHERITS the payment / financing options from its project
 *    (location). `payment_methods_overridden` flips it to its own set — so a
 *    single apartment can be, say, cash-only while the project offers loans.
 *    The own set is a many-to-many onto the `project_payment_methods` dynamic
 *    list, mirroring `location_payment_methods`.
 *  - `note`: a free-text remark shown wherever the unit's details appear.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->boolean('payment_methods_overridden')->default(false)->after('gtm_priority');
            $table->text('note')->nullable()->after('payment_methods_overridden');
        });

        Schema::create('unit_payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->constrained('units')->cascadeOnDelete();
            $table->foreignId('dynamic_list_item_id')->constrained('dynamic_list_items')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['unit_id', 'dynamic_list_item_id'], 'unit_payment_method_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unit_payment_methods');

        Schema::table('units', function (Blueprint $table) {
            $table->dropColumn(['payment_methods_overridden', 'note']);
        });
    }
};
