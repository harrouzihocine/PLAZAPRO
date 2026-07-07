<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Analytics\Models\ActivityLog;
use App\Modules\Settings\Models\User;
use Illuminate\Console\Command;

/**
 * Break-glass unlock from the server console, for when every users.unlock
 * holder has locked themselves out and nobody can reach Settings → Users:
 *
 *   php artisan user:unlock admin@plaza.local
 */
class UnlockUserCommand extends Command
{
    protected $signature = 'user:unlock {login : The email or username of the account to unlock}';

    protected $description = 'Clear a brute-force login lock on an account (break-glass, bypasses the admin UI)';

    public function handle(): int
    {
        $login = (string) $this->argument('login');
        $field = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';

        $user = User::where($field, $login)->first();

        if ($user === null) {
            $this->error("No user with {$field} \"{$login}\".");

            return self::FAILURE;
        }

        if ($user->locked_at === null && $user->failed_login_attempts === 0) {
            $this->info("{$user->name} is not locked.");

            return self::SUCCESS;
        }

        $user->clearLoginLockout();
        ActivityLog::record('account_unlocked', $user, ['via' => 'console']);

        $this->info("{$user->name} is unlocked and can sign in again.");

        return self::SUCCESS;
    }
}
