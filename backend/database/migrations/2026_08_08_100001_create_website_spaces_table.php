<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * website_spaces = morph anchors for media that belongs to the public site
 * itself rather than to a project or unit (today: the 'hero' landing library
 * the owner uploads slideshow photos / the intro video into). One row per
 * well-known key, created lazily by WebsiteSpace::hero().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_spaces', function (Blueprint $table) {
            $table->id();
            $table->string('key', 30)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::drop('website_spaces');
    }
};
