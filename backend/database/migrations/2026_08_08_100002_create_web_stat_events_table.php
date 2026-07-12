<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * web_stat_events = the public showcase's own analytics log (visits, project
 * and unit views, contact clicks), fed by the anonymous POST /public/track
 * batcher. Append-only and PII-free: the visitor is a random client-side key,
 * never an IP or a cookie tied to identity. location_id/unit_id deliberately
 * carry no FK — junk from the open internet must never block inventory writes,
 * and rows must survive whatever happens to the referenced record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('web_stat_events', function (Blueprint $table) {
            $table->id();
            $table->string('session_key', 64);
            $table->string('event', 30);
            $table->unsignedBigInteger('location_id')->nullable();
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->string('path', 300)->nullable();
            $table->string('referrer', 300)->nullable();
            $table->string('locale', 5)->nullable();
            $table->boolean('is_mobile')->default(false);
            $table->timestamp('created_at');

            // The reads are all "window of days, then slice": by event, by
            // project, by unique visitor.
            $table->index(['created_at', 'session_key']);
            $table->index(['event', 'created_at']);
            $table->index(['location_id', 'created_at']);
            $table->index(['unit_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::drop('web_stat_events');
    }
};
