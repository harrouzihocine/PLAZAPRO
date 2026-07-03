<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Sweep expired 48h reservation holds. Requires a running scheduler in the
// container (schedule:work, or cron calling schedule:run). See Phase 7.
Schedule::command('holds:expire')->everyFiveMinutes()->withoutOverlapping();

// Pipeline reminders: generate reminders for due/overdue next actions (hourly),
// then dispatch the pending ones to their assigned agents (every minute).
Schedule::command('actions:mark-overdue')->hourly()->withoutOverlapping();
Schedule::command('reminders:dispatch')->everyMinute()->withoutOverlapping();

// Every morning: remind each user of the calls / visits / tasks that involve
// them and are due today or tomorrow (repeats daily until completed).
Schedule::command('reminders:upcoming-digest')->dailyAt('08:00')->withoutOverlapping();

// Flip past-due, unpaid payment-schedule instalments to overdue (daily).
Schedule::command('schedules:mark-overdue')->dailyAt('00:15')->withoutOverlapping();
