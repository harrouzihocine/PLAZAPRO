<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * media_shares = an agent's hand-picked media bundle sent to ONE client over
 * WhatsApp. The unguessable token is the whole authorization: the public
 * share page (and its file/thumb streaming) serves exactly the listed items,
 * published project or not, until the link expires. Items live in their own
 * table (not JSON) so the per-request streaming gate is one indexed lookup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_shares', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('client_id')->constrained('clients');
            // Display heading for the share page — snapshot of the project /
            // unit label at send time (renames later don't rewrite sent links).
            $table->string('title', 200);
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });

        Schema::create('media_share_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_share_id')->constrained('media_shares')->cascadeOnDelete();
            $table->foreignId('media_id')->constrained('media');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->unique(['media_share_id', 'media_id']);
        });
    }

    public function down(): void
    {
        Schema::drop('media_share_items');
        Schema::drop('media_shares');
    }
};
