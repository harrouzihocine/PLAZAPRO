<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deal workflow rework — track each apartment on a deal on its own:
 *
 *  - deals.call_id: a deal can now be born from a CALL log too (provenance),
 *    not only a visit log / direct permission.
 *  - deal_items get their own lifecycle: `state` (reserved → won / lost) +
 *    `agreed_price` + `closed_at`, so a client can buy one apartment and pass
 *    on the other. Box items carry `parent_item_id` (the apartment item they
 *    ride with) and `box_linked` (this deal linked the box to the apartment,
 *    so a lost close reverts the link).
 *  - payment_schedules / versements get `unit_id`: one instalment plan per
 *    won apartment, every payment recorded against a specific apartment
 *    (backfilled from the project's stamped unit for existing rows).
 *  - reservations.expires_at becomes nullable: a hold backing an open deal
 *    does not expire — only closing the deal releases or converts it.
 *  - app_settings: scalar key-value settings (first key: the reservation hold
 *    duration in hours, previously hardcoded to 48).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            // The call log the deal came from (deals born on the phone).
            $table->foreignId('call_id')->nullable()->after('visit_id')
                ->constrained('calls')->nullOnDelete();
        });

        Schema::table('deal_items', function (Blueprint $table) {
            // Box items ride with an apartment item on the same deal.
            $table->foreignId('parent_item_id')->nullable()->after('box_id')
                ->constrained('deal_items')->nullOnDelete();
            // Per-item lifecycle: reserved → won / lost (apartment items).
            $table->string('state')->default('reserved')->after('parent_item_id');
            $table->decimal('agreed_price', 12, 2)->nullable()->after('state');
            $table->timestamp('closed_at')->nullable()->after('agreed_price');
            // True when THIS deal linked the box to the apartment — reverted
            // if the item is lost / removed from the deal.
            $table->boolean('box_linked')->default(false)->after('closed_at');
        });

        // Existing items inherit their deal's outcome (legacy deals closed
        // all-together); their agreed price stays on the deal total.
        DB::table('deal_items')
            ->join('deals', 'deals.id', '=', 'deal_items.deal_id')
            ->whereIn('deals.state', ['won', 'lost'])
            ->update(['deal_items.state' => DB::raw('deals.state')]);

        Schema::table('payment_schedules', function (Blueprint $table) {
            $table->foreignId('unit_id')->nullable()->after('client_project_id')
                ->constrained('units')->nullOnDelete();
            $table->index(['client_project_id', 'unit_id']);
        });

        Schema::table('versements', function (Blueprint $table) {
            $table->foreignId('unit_id')->nullable()->after('client_project_id')
                ->constrained('units')->nullOnDelete();
            $table->index(['client_project_id', 'unit_id']);
        });

        // Existing money rows belong to the project's stamped (sold) unit.
        foreach (['payment_schedules', 'versements'] as $moneyTable) {
            DB::table($moneyTable)
                ->join('client_projects', 'client_projects.id', '=', "$moneyTable.client_project_id")
                ->whereNotNull('client_projects.unit_id')
                ->update(["$moneyTable.unit_id" => DB::raw('client_projects.unit_id')]);
        }

        Schema::table('reservations', function (Blueprint $table) {
            // Null = no expiry (the hold backs an open deal).
            $table->timestamp('expires_at')->nullable()->change();
        });

        // Holds backing a still-open deal stop expiring right away.
        DB::table('reservations')
            ->where('hold_status', 'active')
            ->whereIn('client_project_id', function ($query) {
                $query->select('client_project_id')->from('deals')
                    ->where('state', 'reserved')->where('status', 'active');
            })
            ->update(['expires_at' => null]);

        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('value');
            $table->timestamps();
        });

        DB::table('app_settings')->insert([
            'key' => 'reservation_hold_hours',
            'value' => '48',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');

        Schema::table('reservations', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable(false)->change();
        });

        foreach (['versements', 'payment_schedules'] as $moneyTable) {
            Schema::table($moneyTable, function (Blueprint $table) use ($moneyTable) {
                $table->dropIndex("{$moneyTable}_client_project_id_unit_id_index");
                $table->dropConstrainedForeignId('unit_id');
            });
        }

        Schema::table('deal_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_item_id');
            $table->dropColumn(['state', 'agreed_price', 'closed_at', 'box_linked']);
        });

        Schema::table('deals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('call_id');
        });
    }
};
