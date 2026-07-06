<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Server-side mirror of a user's unsaved modal drafts — METADATA ONLY (key,
 * label, route, timestamps), never the typed form contents. It exists so the
 * drafts-oversight page can see who is sitting on unfinished work and clear it;
 * the actual draft data stays in the user's browser (localStorage).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('draft_key');
            $table->string('label')->nullable();
            $table->string('route', 1000)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'draft_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_drafts');
    }
};
