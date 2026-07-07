<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Brute-force lockout: consecutive failed password attempts are counted per
 * account and, past the configured limit (app setting `login_max_attempts`),
 * the account is locked (`locked_at`). A locked account refuses login — even
 * with the correct password — until an admin unlocks it in Settings → Users
 * (or the optional `login_lockout_minutes` window expires).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedSmallInteger('failed_login_attempts')->default(0)->after('is_active');
            $table->timestamp('locked_at')->nullable()->after('failed_login_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['failed_login_attempts', 'locked_at']);
        });
    }
};
