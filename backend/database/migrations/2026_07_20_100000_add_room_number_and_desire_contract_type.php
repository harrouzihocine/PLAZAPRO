<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Room number" (F2 / F3 / …) is its own dynamic-list attribute, distinct from a
 * unit's type — captured on inventory and on what a client is looking for. And a
 * desire can now name a preferred sale contract (contract_type), mirroring the
 * project-level contract a unit inherits from its location. Both are seeded into
 * the `room_numbers` / `contract_types` dynamic lists (DynamicListSeeder).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->foreignId('room_number_id')->nullable()->after('type_id')
                ->constrained('dynamic_list_items')->nullOnDelete();
        });

        Schema::table('desires', function (Blueprint $table) {
            $table->foreignId('room_number_id')->nullable()->after('type_id')
                ->constrained('dynamic_list_items')->nullOnDelete();
            // The sale contract the client wants (a `contract_types` item) — the
            // desire-side mirror of a project's contract_type.
            $table->foreignId('contract_type_id')->nullable()->after('room_number_id')
                ->constrained('dynamic_list_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->dropConstrainedForeignId('room_number_id');
        });

        Schema::table('desires', function (Blueprint $table) {
            $table->dropConstrainedForeignId('room_number_id');
            $table->dropConstrainedForeignId('contract_type_id');
        });
    }
};
