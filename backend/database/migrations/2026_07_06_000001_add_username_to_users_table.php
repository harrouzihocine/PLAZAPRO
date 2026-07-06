<?php

declare(strict_types=1);

use App\Modules\Settings\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Add a stable login handle (`username`) alongside the email. Users can sign in
 * with either. The username is set by an admin and never changed by the owner.
 *
 * Nullable + unique so existing rows can be backfilled deterministically from
 * their email local-part before the app starts requiring it on new users.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->nullable()->unique()->after('email');
        });

        // Backfill existing users: derive a handle from the email local-part,
        // slugified, disambiguated with a numeric suffix on collision.
        $taken = [];
        User::query()->orderBy('id')->get(['id', 'email'])->each(function (User $user) use (&$taken) {
            $base = Str::of($user->email)->before('@')->lower()->replaceMatches('/[^a-z0-9_.]/', '')->value();
            $base = $base !== '' ? $base : 'user';

            $handle = $base;
            $n = 1;
            while (isset($taken[$handle])) {
                $handle = $base.($n++);
            }
            $taken[$handle] = true;

            $user->forceFill(['username' => $handle])->saveQuietly();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn('username');
        });
    }
};
