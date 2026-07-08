<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Per-user push preferences: a category → bool map (null = everything on).
// Gates ONLY the FCM system-tray channel — the in-app bell and live broadcast
// always deliver, so muting your phone never hides anything in the app.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('push_prefs')->nullable()->after('avatar_path');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('push_prefs');
        });
    }
};
