<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Desire criteria go multi-select: a client can want several wilayas/communes,
 * project types, room counts (F2 OR F3), contract types and floors at once —
 * same "no rows = no preference" semantics desire_locations already has. The
 * old single FK columns are backfilled into the pivots and dropped: the
 * matchers read pivots only, so keeping the columns would let them go stale.
 *
 * The four dynamic-list criteria share ONE pivot (desire_list_items) with a
 * `field` discriminator so every row keeps a real FK to dynamic_list_items;
 * wilayas/communes get their own tables for the same reason.
 */
return new class extends Migration
{
    private const LIST_FIELDS = [
        'type' => 'type_id',
        'room_number' => 'room_number_id',
        'contract_type' => 'contract_type_id',
        'floor' => 'floor_id',
    ];

    public function up(): void
    {
        Schema::create('desire_wilayas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('desire_id')->constrained('desires')->cascadeOnDelete();
            $table->foreignId('wilaya_id')->constrained('wilayas')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['desire_id', 'wilaya_id']);
        });

        Schema::create('desire_communes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('desire_id')->constrained('desires')->cascadeOnDelete();
            $table->foreignId('commune_id')->constrained('communes')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['desire_id', 'commune_id']);
        });

        Schema::create('desire_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('desire_id')->constrained('desires')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('dynamic_list_items')->cascadeOnDelete();
            $table->string('field'); // type | room_number | contract_type | floor
            $table->timestamps();

            $table->unique(['desire_id', 'item_id', 'field']);
        });

        DB::statement(
            'INSERT INTO desire_wilayas (desire_id, wilaya_id, created_at, updated_at)
             SELECT id, wilaya_id, NOW(), NOW() FROM desires WHERE wilaya_id IS NOT NULL',
        );
        DB::statement(
            'INSERT INTO desire_communes (desire_id, commune_id, created_at, updated_at)
             SELECT id, commune_id, NOW(), NOW() FROM desires WHERE commune_id IS NOT NULL',
        );
        foreach (self::LIST_FIELDS as $field => $column) {
            DB::statement(
                "INSERT INTO desire_list_items (desire_id, item_id, field, created_at, updated_at)
                 SELECT id, {$column}, '{$field}', NOW(), NOW() FROM desires WHERE {$column} IS NOT NULL",
            );
        }

        Schema::table('desires', function (Blueprint $table) {
            $table->dropConstrainedForeignId('wilaya_id');
            $table->dropConstrainedForeignId('commune_id');
            $table->dropConstrainedForeignId('type_id');
            $table->dropConstrainedForeignId('room_number_id');
            $table->dropConstrainedForeignId('contract_type_id');
            $table->dropConstrainedForeignId('floor_id');
        });
    }

    public function down(): void
    {
        Schema::table('desires', function (Blueprint $table) {
            $table->foreignId('wilaya_id')->nullable()->after('client_project_id')
                ->constrained('wilayas')->nullOnDelete();
            $table->foreignId('commune_id')->nullable()->after('wilaya_id')
                ->constrained('communes')->nullOnDelete();
            $table->foreignId('type_id')->nullable()->after('commune_id')
                ->constrained('dynamic_list_items')->nullOnDelete();
            $table->foreignId('room_number_id')->nullable()->after('type_id')
                ->constrained('dynamic_list_items')->nullOnDelete();
            $table->foreignId('contract_type_id')->nullable()->after('room_number_id')
                ->constrained('dynamic_list_items')->nullOnDelete();
            $table->foreignId('floor_id')->nullable()->after('contract_type_id')
                ->constrained('dynamic_list_items')->nullOnDelete();
        });

        // Best-effort: a multi-valued desire collapses to its first-captured value.
        DB::statement(
            'UPDATE desires d SET wilaya_id =
             (SELECT wilaya_id FROM desire_wilayas p WHERE p.desire_id = d.id ORDER BY p.id LIMIT 1)',
        );
        DB::statement(
            'UPDATE desires d SET commune_id =
             (SELECT commune_id FROM desire_communes p WHERE p.desire_id = d.id ORDER BY p.id LIMIT 1)',
        );
        foreach (self::LIST_FIELDS as $field => $column) {
            DB::statement(
                "UPDATE desires d SET {$column} =
                 (SELECT item_id FROM desire_list_items p WHERE p.desire_id = d.id AND p.field = '{$field}' ORDER BY p.id LIMIT 1)",
            );
        }

        Schema::dropIfExists('desire_list_items');
        Schema::dropIfExists('desire_communes');
        Schema::dropIfExists('desire_wilayas');
    }
};
