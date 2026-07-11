<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * app_settings.value was a VARCHAR(255) — fine for the numeric knobs it was
 * born for, but the public showcase stores paragraph-length settings there
 * (website_about_{en,fr,ar}, validated up to 5000 chars) and strict SQL mode
 * rejected the insert with a 1406. TEXT fits every current and future value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->text('value')->change();
        });
    }

    public function down(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->string('value')->change();
        });
    }
};
