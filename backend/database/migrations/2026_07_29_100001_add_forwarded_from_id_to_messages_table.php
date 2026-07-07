<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Message forwarding (Messenger-style): a forwarded message is a NEW message in
 * the target thread, owned by the forwarder, carrying a copy of the source's
 * body/attachments plus this provenance pointer (renders the "Forwarded" tag).
 * nullOnDelete: provenance is cosmetic — losing it must never block a cleanup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('forwarded_from_id')->nullable()->after('reply_to_id')
                ->constrained('messages')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('forwarded_from_id');
        });
    }
};
