<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two client-intake changes:
 *  - Names become optional — walk-ins often give a phone before a name, so the
 *    phone is the only required field (the UI shows "No name" until captured).
 *  - Referral capture: when the lead source is "referral", who referred them
 *    (name + phone) is stored on the client itself.
 *  - Contract/identity fields needed to close a deal: ID document (national id /
 *    driving licence / passport) + number, birth date/place, nationality,
 *    address and occupation. All optional — filled as the deal firms up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('first_name')->nullable()->change();
            $table->string('last_name')->nullable()->change();

            $table->string('referrer_name')->nullable()->after('rating_id');
            $table->string('referrer_phone', 50)->nullable()->after('referrer_name');

            $table->string('id_document_type', 30)->nullable()->after('notes');
            $table->string('id_document_number', 100)->nullable()->after('id_document_type');
            $table->date('birth_date')->nullable()->after('id_document_number');
            $table->string('birth_place')->nullable()->after('birth_date');
            $table->string('nationality', 100)->nullable()->after('birth_place');
            $table->string('address', 500)->nullable()->after('nationality');
            $table->string('occupation')->nullable()->after('address');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('first_name')->nullable(false)->change();
            $table->string('last_name')->nullable(false)->change();
            $table->dropColumn([
                'referrer_name', 'referrer_phone',
                'id_document_type', 'id_document_number',
                'birth_date', 'birth_place', 'nationality', 'address', 'occupation',
            ]);
        });
    }
};
