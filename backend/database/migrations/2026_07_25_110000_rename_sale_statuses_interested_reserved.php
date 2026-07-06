<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vocabulary swap across the whole app — "reserved" used to mean a client hold
 * (open deal / backup queue) and "onhold" the deposit lock; that read wrong to
 * the business. New terms:
 *
 *   units/boxes sale_status:  reserved  → interested   (client hold / backups)
 *                             onhold    → reserved     (holding-deposit lock)
 *   deals.state / deal_items.state: reserved → open    (deal not yet won/lost)
 *   client_projects.stage:    reserved  → deal         (an open deal carries it)
 *
 * UPDATE order is load-bearing: `reserved` must move to `interested` BEFORE
 * `onhold` claims the freed `reserved` value.
 *
 * Also renames the columns/settings/permission that carried the old words:
 *   units.onhold_expires_at → reserved_expires_at
 *   units.onhold_project_id → reserved_project_id
 *   app_settings reservation_hold_hours → interest_hold_hours
 *                onhold_hold_hours      → reserved_hold_hours
 *   permission units.reserve → units.interest (role grants ride on the id)
 *   notification kind onhold_lapsed → reserved_lapsed (icon mapping)
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            // 1) reserved → interested first (frees the `reserved` value)…
            DB::table('units')->where('sale_status', 'reserved')->update(['sale_status' => 'interested']);
            DB::table('boxes')->where('sale_status', 'reserved')->update(['sale_status' => 'interested']);

            // 2) …then onhold → reserved claims it (units only; boxes never hold).
            DB::table('units')->where('sale_status', 'onhold')->update(['sale_status' => 'reserved']);

            // Deal lifecycle: reserved → open.
            DB::table('deals')->where('state', 'reserved')->update(['state' => 'open']);
            DB::table('deal_items')->where('state', 'reserved')->update(['state' => 'open']);

            // Pipeline stage: reserved → deal.
            DB::table('client_projects')->where('stage', 'reserved')->update(['stage' => 'deal']);

            // Settings keys (values are kept).
            DB::table('app_settings')->where('key', 'reservation_hold_hours')->update(['key' => 'interest_hold_hours']);
            DB::table('app_settings')->where('key', 'onhold_hold_hours')->update(['key' => 'reserved_hold_hours']);

            // Permission slug: role grants reference the id, so they survive.
            DB::table('permissions')->where('slug', 'units.reserve')->update([
                'slug' => 'units.interest',
                'name' => 'Units Interest',
                'description' => 'Mark a unit as Interested for a client (places a hold).',
            ]);

            // Durable bell records (kind + old-vocabulary texts) are remapped in
            // the follow-up migration via JSON functions — a plain string
            // REPLACE is unreliable against MySQL's JSON rendering.
        });

        // DDL after the data swap (MySQL DDL is non-transactional anyway). The
        // composite index carries the old column name — drop, rename, re-add.
        Schema::table('units', function (Blueprint $table) {
            $table->dropIndex(['sale_status', 'onhold_expires_at']);
            $table->renameColumn('onhold_expires_at', 'reserved_expires_at');
            $table->renameColumn('onhold_project_id', 'reserved_project_id');
        });
        Schema::table('units', function (Blueprint $table) {
            // The expiry sweeper scans (sale_status = reserved, reserved_expires_at <= now).
            $table->index(['sale_status', 'reserved_expires_at']);
        });

        // A fresh deal / deal item is born `open` now. DealItem::create relies
        // on the column default, so this is behaviour, not cosmetics.
        Schema::table('deals', function (Blueprint $table) {
            $table->string('state')->default('open')->change();
        });
        Schema::table('deal_items', function (Blueprint $table) {
            $table->string('state')->default('open')->change();
        });
    }

    public function down(): void
    {
        Schema::table('deal_items', function (Blueprint $table) {
            $table->string('state')->default('reserved')->change();
        });
        Schema::table('deals', function (Blueprint $table) {
            $table->string('state')->default('reserved')->change();
        });

        Schema::table('units', function (Blueprint $table) {
            $table->dropIndex(['sale_status', 'reserved_expires_at']);
            $table->renameColumn('reserved_expires_at', 'onhold_expires_at');
            $table->renameColumn('reserved_project_id', 'onhold_project_id');
        });
        Schema::table('units', function (Blueprint $table) {
            $table->index(['sale_status', 'onhold_expires_at']);
        });

        DB::transaction(function () {
            DB::table('permissions')->where('slug', 'units.interest')->update([
                'slug' => 'units.reserve',
                'name' => 'Units Reserve',
                'description' => 'Put a unit on hold for a client.',
            ]);

            DB::table('app_settings')->where('key', 'reserved_hold_hours')->update(['key' => 'onhold_hold_hours']);
            DB::table('app_settings')->where('key', 'interest_hold_hours')->update(['key' => 'reservation_hold_hours']);

            DB::table('client_projects')->where('stage', 'deal')->update(['stage' => 'reserved']);
            DB::table('deal_items')->where('state', 'open')->update(['state' => 'reserved']);
            DB::table('deals')->where('state', 'open')->update(['state' => 'reserved']);

            // Reverse order: reserved → onhold first, then interested → reserved.
            DB::table('units')->where('sale_status', 'reserved')->update(['sale_status' => 'onhold']);
            DB::table('boxes')->where('sale_status', 'interested')->update(['sale_status' => 'reserved']);
            DB::table('units')->where('sale_status', 'interested')->update(['sale_status' => 'reserved']);
        });
    }
};
