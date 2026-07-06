<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who gets credit for a sale, captured when an apartment is won: the sale
 * agent(s) who did the marketing, the in-site agent(s) who ran the site visits,
 * and any others. Stored as a small JSON map of user-id lists
 * ({ sale: [...], insite: [...], other: [...] }) on the won apartment item.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deal_items', function (Blueprint $table) {
            $table->json('credits')->nullable()->after('closed_at');
        });
    }

    public function down(): void
    {
        Schema::table('deal_items', function (Blueprint $table) {
            $table->dropColumn('credits');
        });
    }
};
