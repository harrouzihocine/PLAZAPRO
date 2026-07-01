<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extend the default users table with PLAZA PRO columns: exactly one role,
 * an optional department, activity/traceability columns, and profile fields.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable()->after('email')->constrained('roles')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->after('role_id')->constrained('departments')->nullOnDelete();
            $table->string('phone')->nullable()->after('department_id');
            $table->string('avatar_path')->nullable()->after('phone');
            $table->boolean('is_active')->default(true)->after('avatar_path');
            $table->timestamp('last_login_at')->nullable()->after('is_active');
            // Base-model traceability columns:
            $table->string('status')->default('active')->after('last_login_at');
            $table->string('cancellation_reason')->nullable()->after('status');

            $table->index(['status', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('role_id');
            $table->dropConstrainedForeignId('department_id');
            $table->dropColumn([
                'phone', 'avatar_path', 'is_active', 'last_login_at', 'status', 'cancellation_reason',
            ]);
        });
    }
};
