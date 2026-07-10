<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Console;

use App\Modules\Collaboration\Notifications\DomainNotification;
use App\Modules\Pipeline\Models\DutySession;
use App\Modules\Settings\Models\User;
use Illuminate\Console\Command;

/**
 * The 07:20 duty nudge: every field agent NOT already on duty gets a
 * bell + tray push reminding them to flip the My Day switch. Friday is the
 * rest day — the scheduler still fires but the command stands down, so the
 * skip logic lives here where a test can reach it.
 */
class RemindDutyStart extends Command
{
    protected $signature = 'duty:remind';

    protected $description = 'Remind field agents (not already on duty) to open duty — skipped on Fridays';

    public function handle(): int
    {
        if (now()->isFriday()) {
            $this->info('Friday — no duty reminder.');

            return self::SUCCESS;
        }

        $onDuty = DutySession::query()->open()->pluck('user_id');

        $agents = User::query()->active()->where('is_active', true)
            ->whereHas('role', fn ($q) => $q->where('is_agent', true))
            ->whereNotIn('id', $onDuty)
            ->get();

        $notification = new DomainNotification(
            kind: 'duty_reminder',
            key: 'duty_reminder',
            link: '/my-day',
        );

        foreach ($agents as $agent) {
            $agent->notify($notification);
        }

        $this->info("Reminded {$agents->count()} agent(s).");

        return self::SUCCESS;
    }
}
